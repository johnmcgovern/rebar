# Installing Rebar

This guide adds Rebar to an existing Drupal 11 site. Rebar is an experiment:
try it on a staging copy first, and keep the rollback steps at the end handy.

You will:

1. Build the `rebar.so` PHP extension for your server's PHP.
2. Install the `rebar` Drupal module.
3. Load the extension for your site's PHP processes (web and Drush).
4. Turn features on in `settings.php`.
5. Check that it works.

## What you get

Each feature is switched on separately in `settings.php`:

| Feature | Replaces | Setting |
|---|---|---|
| Shared Rust cache store | The database cache backend (and APCu for 4 bins) | `$settings['cache']['default'] = 'cache.backend.rebar_shared'` |
| Rust cache-tag checksums | The `cachetags` database table | `$settings['rebar']['cache_tags'] = 'shared'` |
| CKEditor 5 plugin cache (PHP only) | Recomputing enabled CKEditor 5 plugins on every request | `$settings['rebar']['ckeditor5_cache'] = TRUE` |

The CKEditor 5 cache needs only the module, not the extension. If you just want
that, do steps 2 and 4 (the CKEditor line only), then step 5.

## Requirements

- **Drupal 11** (tested on 11.4.7).
- **PHP 8.3 or newer, non-thread-safe (NTS)**, on Linux, x86_64 or arm64
  (tested on 8.3 and 8.5). PHP-FPM is assumed below; other setups (such as
  Apache mod_php) are untested.
- **One web server.** The shared store is shared by the processes on one
  machine, like APCu. Several web servers behind a load balancer would each
  have their own store and not see each other's cache clears. Don't use it
  there.
- **A tmpfs for the store**, normally `/dev/shm`. It must be larger than the
  store's size (256MB by default). In Docker the default `/dev/shm` is only
  64MB: start the container with `--shm-size=1g` or more.
- **To build:** Docker, or a Rust toolchain plus your PHP's development
  headers (see step 1).

## 1. Build the extension

The extension must be built for your server's exact PHP minor version (8.3,
8.4, 8.5...) and architecture. Check the server with:

```sh
php -r 'echo PHP_VERSION, " ", php_uname("m"), " ", PHP_ZTS ? "ZTS" : "NTS", PHP_EOL;'
```

### Option A: with Docker (no toolchain on the server)

From a clone of this repo, on any machine with Docker:

```sh
PHP_VERSION=8.5 PLATFORM=linux/amd64 bin/build-ext-linux
```

Use your server's PHP version, and `PLATFORM=linux/arm64` for arm64 servers.
The result is `ext/dist/rebar-php<version>-<arch>.so`. The build runs under
emulation when the platform differs from your machine's, so the first one
takes several minutes.

### Option B: on the server, with Rust

You need Rust (via [rustup](https://rustup.rs)), clang/libclang, and your
PHP's development package (for example `php8.5-dev` on Debian/Ubuntu), which
provides `php-config`:

```sh
cd ext
cargo build --release
# The extension is target/release/librebar.so
```

### Check the build

On the server:

```sh
php -n -d extension=/path/to/rebar.so -r 'echo rebar_hello("server"), PHP_EOL;'
# Hello server, from Rust 0.1.0
```

`Module compiled with module API=...` means it was built for a different PHP
version: rebuild with the right `PHP_VERSION`.

## 2. Install the Drupal module

Copy `drupal/web/modules/custom/rebar` from this repo into your site's
`web/modules/custom/`, then enable it:

```sh
drush en rebar
```

Enable the module **before** step 4: the cache settings refer to services the
module defines.

## 3. Load the extension

Put `rebar.so` somewhere stable and readable by the web server user, for
example `/var/www/example.com/rebar/rebar.so`.

Every process that runs your site must load it: the web workers **and**
Drush, cron and any other CLI scripts. A process without it would use the
database cache while the others use the Rust store, and they would stop
seeing each other's changes.

### Web: one PHP-FPM pool per site (recommended)

Loading the extension in a dedicated pool keeps it away from other sites on
the same server. In your site's pool file (for example
`/etc/php/8.5/fpm/pool.d/example.conf`):

```ini
php_admin_value[extension] = /var/www/example.com/rebar/rebar.so
```

If the site shares the default `www` pool with other sites, create its own
pool first (a copy of `www.conf` with a new `[name]` and `listen` socket), and
point the site's nginx `fastcgi_pass` at that socket. `deploy/server/` has
the pool files used for the benchmark.

Check and reload:

```sh
sudo php-fpm8.5 -t && sudo systemctl reload php8.5-fpm
```

If the whole server runs only this site, you can instead load it for every
PHP process with an ini file, e.g. `/etc/php/8.5/mods-available/rebar.ini`
containing `extension=/var/www/example.com/rebar/rebar.so`, enabled for both
`fpm` and `cli`.

### CLI: a Drush wrapper

With a per-pool extension, Drush needs a wrapper that loads it too. Copy
`deploy/server/drush` to your site's `bin/drush`:

```sh
#!/bin/sh
SITE="$(cd "$(dirname "$0")/.." && pwd)"
exec php -d extension="$SITE/rebar/rebar.so" "$SITE/vendor/bin/drush.php" --root="$SITE" "$@"
```

From now on use `bin/drush` for this site, and run it as the web server user
so it can open the shared store: `sudo -u www-data bin/drush cr`. Point cron
jobs at the wrapper as well.

## 4. Turn features on

Add to the end of `settings.php` (adjust the path and size):

```php
// rebar: Rust-backed services.
$settings['rebar'] = [
  // Shared LMDB store on tmpfs, used by every PHP process of this site.
  'shared_path' => '/dev/shm/rebar-example',
  'shared_size_mb' => 256,
  // Cache tag counters in the shared store. Compiled into the service
  // container, so set regardless of whether this process has the extension.
  'cache_tags' => 'shared',
  // Cache which CKEditor 5 plugins each editor enables (PHP only).
  'ckeditor5_cache' => TRUE,
];
if (extension_loaded('rebar')) {
  $settings['cache']['default'] = 'cache.backend.rebar_shared';
  // These bins name their own default backend, which outranks the one above.
  foreach (['bootstrap', 'config', 'discovery', 'routes'] as $bin) {
    $settings['cache']['bins'][$bin] = 'cache.backend.rebar_shared';
  }
}
elseif (PHP_SAPI === 'cli') {
  fwrite(STDERR, "WARNING: rebar extension not loaded; use bin/drush so caches stay consistent.\n");
}
```

Then rebuild caches and reload PHP:

```sh
sudo -u www-data bin/drush cr
sudo systemctl reload php8.5-fpm
```

Notes:

- `shared_path` must be writable by the web server user, and by whoever runs
  Drush (hence `sudo -u www-data`). Use one path per site.
- `shared_size_mb` must fit in the tmpfs. When the store reaches 70% it is
  emptied completely and refills; `/admin/reports/rebar` shows how full it is.
- With `cache_tags` on, a process without the extension fails with
  `Call to undefined function rebar_tags_get()`. That is deliberate: it is
  safer than silently using different tag counters than the web workers.

## 5. Check it works

```sh
sudo -u www-data bin/drush php:eval '
  echo get_class(\Drupal::cache("render")), PHP_EOL;
  echo get_class(\Drupal::service("cache_tags.invalidator.checksum")), PHP_EOL;
  echo get_class(\Drupal::service("plugin.manager.ckeditor5.plugin")), PHP_EOL;'
# Drupal\rebar\Cache\RustBackend
# Drupal\rebar\Cache\RustCacheTagsChecksum
# Drupal\rebar\CKEditor5\CachedCKEditor5PluginManager
```

Then:

- **Status page:** `/admin/reports/rebar` shows the store's bins, items, size
  and hit rate. The site's status report (`/admin/reports/status`, or
  `bin/drush core:requirements`) has a "Rebar PHP extension" entry: OK when
  loaded, an error when `cache_tags` is on but this process lacks it.
- **Consistency:** change something from Drush (for example
  `bin/drush cset system.site name "Test"`) and reload a page a few times. The
  change should appear on every request. If it appears only sometimes, some
  process isn't loading the extension.

## Updating the extension

Running PHP workers have `rebar.so` memory-mapped. **Never overwrite it in
place**, which can crash them. Upload the new file beside it, rename it over
the old one, then reload PHP:

```sh
cp rebar-new.so /var/www/example.com/rebar/rebar.so.new
mv /var/www/example.com/rebar/rebar.so.new /var/www/example.com/rebar/rebar.so
sudo systemctl reload php8.5-fpm
sudo -u www-data bin/drush cr
```

## Rolling back

In this order:

1. Remove the Rebar block from `settings.php`.
2. `vendor/bin/drush cr` (plain Drush is fine now), and reload PHP-FPM.
3. `vendor/bin/drush pmu rebar`.
4. Remove the `php_admin_value[extension]` line (or the ini file), reload
   PHP-FPM, and delete the store directory (for example
   `/dev/shm/rebar-example`).

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Module compiled with module API=...` at PHP startup | Built for another PHP version | Rebuild with the server's `PHP_VERSION` |
| `You have requested a non-existent service "cache.backend.rebar_shared"` | Settings added before the module was enabled | Remove the settings, enable the module, re-add them |
| `Call to undefined function rebar_tags_get()` | A process without the extension (usually plain `vendor/bin/drush`) | Use `bin/drush`, or load the extension for that process |
| `cannot create /dev/shm/...` or permission errors | The store path isn't writable by this user | Run Drush as the web server user; fix the directory's owner |
| Changes made in Drush show up only sometimes, or not at all | Some processes don't load the extension | Load it for every web and CLI process of the site |
| PHP workers die with SIGBUS | The tmpfs is smaller than `shared_size_mb` | Enlarge the tmpfs or lower the size |
| Benchmarks swing wildly between runs | Cron running (search indexing after bulk content changes) | Run `bin/drush cron` to completion before measuring |

More background on each of these is in [FINDINGS.md](FINDINGS.md).
