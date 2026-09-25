# Rebar

**Rust underneath Drupal: faster core services, with no changes to contrib.**

Rebar replaces some of Drupal's core services with faster implementations,
most of them in Rust, compiled into a PHP extension. Each one swaps in behind
an interface Drupal already has, so core, contrib modules and themes keep
working unchanged. It's an experiment. It was called "drust" until September
2026; raw benchmark output in `bench/*.txt` still uses that name.

On a stock Drupal 11.4 site, Rebar made every tested page 13–38% faster:

| Page | Stock req/s | With Rebar | Change |
|---|---|---|---|
| Node with comment form, logged in | 150 | 206 | +37.6% |
| Taxonomy term, logged in | 345 | 428 | +24.1% |
| Front page, logged in | 393 | 459 | +16.6% |
| Front page, anonymous | 1,906 | 2,346 | +23.1% |

Two identical sites on one host (PHP 8.5 FPM, MariaDB on localhost, APCu),
median of 5 rounds with `ab`. Full numbers, method and caveats are in
[FINDINGS.md](FINDINGS.md).

## What it replaces

| Component | Stock Drupal | Rebar |
|---|---|---|
| Cache storage (all bins) | Database tables, APCu for 4 bins | Rust: an LMDB store in `/dev/shm`, shared by all PHP processes on the machine |
| Cache-tag checksums | The `cachetags` database table | Rust: counters in the same store, offset by a random epoch so a wiped store can't make stale data look valid |
| CKEditor 5 enabled-plugin list | Recomputed twice per request (about 5ms) | PHP: cached, keyed by the editor's and text format's contents |

Each is switched on separately in `settings.php`.

## How it works

```mermaid
flowchart TD
  A[Drupal core, contrib, themes] -->|call core interfaces| B[rebar module<br/>PHP adapters]
  B -->|rebar_* functions| C[rebar.so<br/>Rust, ext-php-rs]
  C --> D[(LMDB store<br/>/dev/shm)]
  B -.->|service swaps| E[CacheBackendInterface<br/>CacheTagsChecksumInterface<br/>CKEditor5PluginManager]
```

The Drupal module implements core's interfaces and hands storage to Rust.
PHP keeps what needs the PHP runtime: serializing values, the request time,
and core's own transaction handling for tag invalidation. Rust owns storage,
expiry, invalidation and the counters.

Every swap is checked against **Drupal core's own test suites**, run
unmodified against the Rebar implementation: core's cache-backend
conformance suite and four CKEditor 5 kernel test classes, 195 tests in all.

## Getting started

See **[INSTALL.md](INSTALL.md)** to add Rebar to an existing Drupal 11 site:
build the extension for your PHP, install the module, load the extension for
your site's PHP processes, and switch features on.

Requirements: Drupal 11, PHP 8.3+ (NTS) on Linux, a single web server.

## Repository layout

| Path | What |
|---|---|
| `ext/` | The Rust PHP extension (`src/store.rs`, `src/shared.rs`, `src/lib.rs`) |
| `drupal/web/modules/custom/rebar/` | The Drupal module and its tests |
| `drupal/` | A local Drupal 11 test site (SQLite) |
| `docker/` | Dev image: PHP 8.5 + Rust + Composer |
| `bin/` | `setup-dev`, `dev`, `build-ext`, `build-ext-linux`, `bench`, `deploy-drupal02` |
| `bench/` | Benchmark scripts, content generator, raw results; `profile/` has the Excimer profiling tools |
| `deploy/server/` | PHP-FPM pools, Drush wrapper and settings used on the benchmark host |
| [FINDINGS.md](FINDINGS.md) | Every bug, fix, gotcha and design lesson so far |

## Development

Everything runs in Docker; nothing needs installing on your machine.

```sh
bin/setup-dev                        # from a fresh clone: image, Composer, extension, site, content
bin/build-ext                        # rebuild ext/rebar.so after changing Rust code
bin/dev vendor/bin/drush status      # run anything against the local site

# The test suite (core's suites against rebar, plus rebar's own):
bin/dev sh -c 'SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite \
  SIMPLETEST_BASE_URL=http://localhost vendor/bin/phpunit \
  -c web/core/phpunit.xml.dist web/modules/custom/rebar/tests'
```

`bin/setup-dev` installs the local site with the `standard` profile plus the
`standard`, `article_tags`, `article_comment` and `page_content_type` recipes
(in Drupal 11.4 the profile no longer creates content types), enables the rebar module and
generates 100 tagged articles with 300 comments. Admin login: `admin` /
`admin`. The site's `settings.php` isn't committed (it holds the hash salt);
Rebar's dev settings are in `sites/default/settings.rebar-dev.php`.

Locally, the `REBAR_CACHE` environment variable picks the cache mode:

- `1`: every bin in Rust, private to one PHP process. Fastest, but other
  processes (Drush, other workers) never see its writes.
- `chained`: Rust in front of the database via core's `ChainedFastBackend`.
- `shared`: the shared LMDB store, consistent across processes.
- unset: core's database backend.

`bin/bench` compares them. `/admin/reports/rebar` shows the stores' contents.

For a Linux server, `PHP_VERSION=8.5 PLATFORM=linux/amd64 bin/build-ext-linux`
builds `ext/dist/rebar-php8.5-x86_64.so`.

### The benchmark setup

`drupal01.example.com` is stock Drupal, `drupal02.example.com` runs Rebar;
they're otherwise identical. `bin/deploy-drupal02` deploys, and
`bench/remote-bench.sh` (on the server) benchmarks both alternately, running
cron to completion first. `bench/summarize.py` prints medians and each site's
spread across rounds; distrust runs with a large spread. `bench/profile/`
holds the Excimer profiling hook and the scripts that summarize its output.

## Limits

- One web server only: the store is shared per machine, like APCu.
- Measured on one small shared host, 3 pages, concurrency 4.
- When the store reaches 70% of its size it's emptied completely (no LRU yet).
- Profiling shows most remaining request time is Drupal's PHP machinery spread
  thinly, with little left that Rust could take over through an extension.
  Details in [FINDINGS.md](FINDINGS.md).
