# Findings

A running log of bugs, fixes, gotchas and architectural learnings from the
Rebar experiments. Newest entries go at the bottom of each section.

## Architecture

### A Rust core cannot host PHP contrib; Rust underneath Drupal can
Contrib modules extend core PHP classes (`ContentEntityBase`, `FormBase`, ...),
call `\Drupal::service()`, and alter PHP arrays by reference. Hosting them on a
Rust core would mean reimplementing Drupal's PHP API. Swapping the
implementation behind a core *interface* (via the service container or
`$settings`) keeps every caller unchanged, so that is the approach here.

### Prove drop-in compatibility with core's own test suites
Core ships abstract conformance tests for its swappable subsystems. For cache
backends, `GenericCacheBackendUnitTestBase` needs only `createCacheBackend()`;
`RustBackend` passes all 11 tests (172 assertions) unmodified. Look for the
equivalent base class before swapping any other subsystem.

### Split responsibilities: PHP adapts, Rust stores
PHP does what needs the PHP runtime: `serialize()`/`unserialize()`, the
request time, and cache tag checksums (core's checksum provider). Rust gets
opaque bytes plus metadata and owns storage, expiry, invalidation and garbage
collection. Passing serialized strings avoids converting arbitrary PHP object
graphs to Rust types.

### A per-process store is unsafe with more than one PHP process
With `REBAR_CACHE=1` every bin lives in Rust memory inside one PHP process.
Writes and deletes from another process (Drush, other FPM workers) never reach
it. Reproduced: `drush cset system.site name ...` followed by `drush cr` left the
web server showing the old name indefinitely. Tag invalidations do propagate,
because tag checksums live in the database, but plain `delete()`/`set()` don't.
The config cache bin is updated with `set()`, so config changes go stale.

### ChainedFastBackend makes a local store consistent
Core already solves this for APCu: `ChainedFastBackend` writes to a consistent
backend (the database) and records a per-bin "last write" timestamp there; each
process ignores fast-backend items created before it. Registering
`ChainedFastBackendFactory` with `cache.backend.rebar` as the fast service
(`cache.backend.rebar_chained`) made config changes from Drush show up on the
next web request. Cost: it gives back roughly half the speed gain, because
misses and the timestamp check still hit the database, and every write goes to
both stores.

### A shared store: LMDB on tmpfs instead of a hand-rolled shm hash table
A cache shared by all PHP processes needs a cross-process hash table, an
allocator and locking inside shared memory. LMDB (via the `heed` crate) is
already that: one memory-mapped file, lock-free MVCC readers, writers
serialized by a robust cross-process mutex, and crash-safe. With the file on
`/dev/shm` it is RAM shared between processes. Durability is traded away
(`NO_SYNC | NO_META_SYNC | WRITE_MAP | MAP_ASYNC`) since cache data is
disposable. `SharedRustBackendTest` runs core's suite against it, plus long
cache IDs, a second PHP process reading and writing the same bin, and a full
map (25 tests, 355 assertions for both stores).

### Evict before LMDB is full: a full map can't be cleared
LMDB is copy-on-write, so even deleting needs free pages. The first design
cleared the store on `MDB_MAP_FULL` and retried, but the clear itself failed
with `MDB_MAP_FULL`. Fix: after each write, read the page counts from
`env.stat()` (cheap, from the meta page) and flush everything once live pages
pass 70% of the map, like APCu's full expunge. Items bigger than 1/8 of the
map are not stored at all (a miss is always acceptable to Drupal). A smarter
eviction policy (LRU, per-bin) is future work.

### Cache tag checksums: keep core's trait, move only the counters
`CacheTagsChecksumTrait` does the subtle work: per-request static caching,
preloading, and delaying invalidations until the database transaction commits
(so another request can't cache stale data before the commit). It only needs
two storage primitives: read counts, increment counts. `RustCacheTagsChecksum`
uses the trait and keeps the counters in the shared LMDB store, so the
`{cachetags}` table is never queried. `RebarServiceProvider` swaps the
`cache_tags.invalidator.checksum` service when
`$settings['rebar']['cache_tags'] === 'shared'`. Verified on the Rebar site
with 4 FPM workers: editing a node from Drush updated its page and the front
page listing on every worker immediately.

### Volatile tag counters need an epoch
With counters in RAM, a flush or a reboot resets them to 0 while items in
other backends (for example database bins) survive. An item cached before an
invalidation would then match the reset counters and look valid again: stale
content. Core's database counters never reset, so it doesn't face this. Fix:
the store holds a random epoch, regenerated whenever the store is created,
cleared or purged (in the same transaction), and added to every counter.
After any reset, every older checksum stops matching.

### Name shared stores explicitly
The first version kept one "current" shared store per process. When the
checksum service opened the site's store, it silently redirected a test's
writes away from the small store the test had opened. Production only ever
opens one, but a silent switch is a trap. Stores are now keyed by path, and
every call names the store it means.

### Service swaps compiled into the container must not depend on the process
Drupal caches the compiled container and shares it between Drush and the web
workers. If `RebarServiceProvider` checked `extension_loaded()`, a Drush run
without the extension could compile a container without the Rust checksum
service, which the web workers would then use. The swap depends on settings
only; a process without the extension fails loudly (undefined function)
instead of quietly using different counters.

## Benchmarks

### Experiment 1: in-process Rust cache (SQLite, `php -S`, page_cache off)
Median of 150 sequential requests per URL.

| Page | Database | Rust only | Chained |
|---|---|---|---|
| `/` | 9.12ms | 6.90ms (-24%) | 7.80ms (-14%) |
| `/node/150` | 13.39ms | 9.86ms (-26%) | 11.96ms (-11%) |
| `/taxonomy/term/30` | 10.67ms | 7.75ms (-27%) | 9.13ms (-14%) |
| `/rss.xml` | 7.54ms | 6.62ms (-12%) | 7.18ms (-5%) |

With `page_cache` on, all backends measure about 3.4ms: a page cache hit is
one lookup and bootstrap dominates.

### Experiment 2: shared Rust store on a server, stock vs Rebar
Production-like setup: Ubuntu 26.04, 4 vCPU, PHP 8.5.4 FPM, MariaDB 11.8 on
localhost, APCu installed. Both sites identical (Drupal 11.4.7, same modules,
same generated content) with identical dedicated pools (`pm = static`, 4
workers); only the Rebar site's pool loads `rebar.so`, with every cache bin in
the shared LMDB store. `ab` over loopback, 300 requests, concurrency 4, sites
measured alternately, median of rounds (`bench/remote-bench.sh`,
`bench/summarize.py`).

A/A first (both stock), to measure noise: up to about ±5% per page.

| Mode | Path | Stock req/s | Rebar req/s | Change | p50 stock / Rebar |
|---|---|---|---|---|---|
| anon | `/` | 2013 | 2144 | +6.5% | 2 / 2ms |
| anon | `/node/51` | 2210 | 2288 | +3.5% | 2 / 2ms |
| anon | `/taxonomy/term/10` | 2090 | 2114 | +1.2% | 2 / 2ms |
| auth | `/` | 394 | 429 | +8.8% | 10 / 9ms |
| auth | `/node/51` | 151 | 159 | +5.9% | 26 / 24ms |
| auth | `/taxonomy/term/10` | 336 | 395 | +17.7% | 12 / 10ms |

Anonymous (page cache hits) is within noise. Logged-in traffic, which runs
through the dynamic page cache and render cache, gains 6-18%. Smaller than the
local SQLite numbers suggested: the stock site is a strong baseline, with
MariaDB on localhost and APCu already holding the
bootstrap/config/discovery/routes bins. Raw output: `bench/server-aa.txt`,
`bench/server-ab-shared.txt`.

### Experiment 3: shared store plus Rust tag checksums on the server
Same setup as experiment 2. Median of 5 rounds; spread is max/min req/s across
rounds (all at or below 1.32x, a clean run).

| Mode | Path | Stock req/s | Rebar req/s | Change | p50 stock / Rebar |
|---|---|---|---|---|---|
| anon | `/` | 1874 | 2221 | +18.5% | 2 / 2ms |
| anon | `/node/51` | 1952 | 2215 | +13.5% | 2 / 2ms |
| anon | `/taxonomy/term/10` | 1880 | 2275 | +21.0% | 2 / 2ms |
| auth | `/` | 366 | 455 | +24.4% | 10 / 9ms |
| auth | `/node/51` | 149 | 165 | +10.7% | 26 / 23ms |
| auth | `/taxonomy/term/10` | 343 | 429 | +25.0% | 11 / 9ms |

Anonymous page cache hits now gain too: every hit validates the page's cache
tags, which used to be a MariaDB query against `{cachetags}` and is now a
shared-memory read. Raw output: `bench/server-ab-shared-tags.txt`.

### Experiment 4: plus the CKEditor 5 plugin cache
Median of 5 rounds, all spreads at or below 1.23x.

| Mode | Path | Stock req/s | Rebar req/s | Change | p50 stock / Rebar |
|---|---|---|---|---|---|
| anon | `/` | 1906 | 2346 | +23.1% | 2 / 2ms |
| anon | `/node/51` | 2184 | 2469 | +13.0% | 2 / 1ms |
| anon | `/taxonomy/term/10` | 2001 | 2307 | +15.3% | 2 / 2ms |
| auth | `/` | 393 | 459 | +16.6% | 10 / 9ms |
| auth | `/node/51` | 150 | 206 | +37.6% | 26 / 19ms |
| auth | `/taxonomy/term/10` | 345 | 428 | +24.1% | 11 / 9ms |

The logged-in node page (the one with an editor) went from +10.7% to +37.6%;
the others are unchanged within noise, as expected. Raw output:
`bench/server-ab-ckeditor.txt`.

### The "noisy" run was automated cron, triggered by our own test content
One full run collapsed to 16-34 req/s logged-in on both sites, including the
untouched stock site. It was first blamed on the busy host (other services,
~1.9GB swapped out). Profiling showed the real cause: 96% of each slow request
was `AutomatedCron` after the response was sent. Generating 100 articles and
300 comments gave `search_cron()` hours of work (402s indexing, then 174s
recalculating word totals on the stock site). While one cron run holds the
lock, every request that ends past the 3-hour interval calls `Cron::run()`,
fails to get the lock and logs "Attempting to re-run cron while it is already
running" through dblog: a ~200ms insert with MariaDB busy indexing. The stock
site logged 4,424 of these that day. Fixes: the benchmark and profile scripts
now wait for any cron run and run cron from Drush first, so automated cron
can't fire mid-run; and `bench/summarize.py` prints each site's max/min spread
across rounds, so a disturbed run is visible. Lesson: after bulk content
changes, let cron (and search indexing) finish before measuring anything.

### Profile: where logged-in time goes after the cache work
Excimer (wall clock, 0.5ms) on the Rebar site, 200 requests per page after cron
settled; throughput while profiling matched the benchmark, so the overhead
is small. ms per request, inclusive (`bench/profile/families.py`):

| Family | front (auth) | node (auth) | term (auth) | front (anon) |
|---|---|---|---|---|
| **whole request** | **8.7** | **24.0** | **9.3** | **1.7** |
| CKEditor 5 settings (`EditorManager::getAttachments`) | 0 | 6.66 | 0 | 0 |
| of which `HTMLRestrictions` | 0 | 5.03 | 0 | 0 |
| Masterminds HTML5 parser (pure PHP) | 0.12 | 1.89 | 0.13 | 0 |
| text filters | 0 | 2.04 | 0 | 0 |
| cache reads (`RustBackend::getMultiple`, PHP side) | 1.25 | 2.57 | 1.55 | 0.10 |
| Rust calls (`rebar_*`) | ~0 | ~0 | ~0 | ~0 |
| database queries | 0.96 | 1.83 | 1.03 | 0.32 |
| container get/create | 1.99 | 2.51 | 2.20 | 0.52 |
| class autoloading | 0.74 | 1.32 | 0.82 | 0.20 |

- The Rust store is effectively free: `rebar_*` calls got almost no samples.
  What remains of cache reads is PHP work around them (unserialize, object
  `__wakeup`, checksum checks).
- The node page renders the comment form on every request (a BigPipe
  placeholder; forms aren't cacheable), and CKEditor 5 recomputes its editor
  settings each time: 6.7ms, 28% of the page. It depends only on
  configuration, so it's a caching problem, not a compute one.
- The rest is Drupal's PHP machinery (render pipeline, container, autoload)
  spread thinly. There is no big self-contained compute kernel left for Rust
  to take over; FFI helps where work is hot and self-contained, like storage.

### CKEditor 5 recomputes its plugin list on every request
`CKEditor5PluginManager::getEnabledDefinitions()` evaluates plugin conditions
and HTML restrictions (parsing allowed HTML with the pure-PHP HTML5 parser)
and runs twice per editor per request, via `getLibraries()` and
`getJSSettings()`: 5.3ms of the 6.7ms CKEditor cost on the logged-in node
page. Its result depends only on configuration, so
`CachedCKEditor5PluginManager` caches the enabled plugin IDs. Not Rust, but
the profile said this is where the time was.

What had to be right:
- **Not the whole settings array.** Core plugins put per-session CSRF tokens
  and request-dependent URLs into their dynamic config (image upload, media
  metadata and preview token, link suggestions). Caching `getJSSettings()`
  would have leaked tokens between users. Only the config-only part is cached;
  `getDynamicPluginConfig()` and `hook_editor_js_settings_alter()` still run
  every request.
- **Key by content, not ID.** The CKEditor 5 admin form validates unsaved,
  modified editor + format pairs. The key is a hash of both entities'
  `toArray()`. Mutation test: keying by `$editor->id()` makes core's
  `SmartDefaultSettingsTest` fail in 12+ cases; the content hash passes.
- **Invalidate with the definitions.** `clearCachedDefinitions()` also
  invalidates the cache tag, and a cached ID missing from the current
  definitions forces a recompute.
- Verified with core's own `CKEditor5PluginManagerTest`, `ValidatorsTest`,
  `SmartDefaultSettingsTest` and `WildcardHtmlSupportTest` run against the
  caching manager (152 tests), and on the server: the Rebar site's editor
  settings (23,760 bytes, two formats) are byte-identical to the stock
  site's apart from masked session tokens, on both a cache miss and a hit.

Residual risk: a contrib plugin whose `getElementsSubset()` depends on
something other than configuration would be cached incorrectly. None in core.
This is also a candidate to propose upstream to Drupal core.

### The Symfony router isn't worth replacing
Measured before considering a Rust router (same profiles, ms per request):

| | front (auth) | node (auth) | term (auth) | front (anon) |
|---|---|---|---|---|
| Route lookup (`RouteProvider`) + Symfony `UrlMatcher` | 0.07 | 0.04 | 0.08 | 0 |
| `Router::matchRequest` in total | 0.55 | 0.98 | 0.93 | 0 |
| Route access checks | 0.47 | 0.29 | 0.22 | 0 |
| URL generation for links | 0.21 | 0.47 | 0.20 | 0 |

Drupal already narrows routes with an indexed lookup (cached, now in the
Rust store), so the matching algorithm is under 1% of a request. Most of
`matchRequest` is parameter conversion (loading the node from the URL), which
is entity loading. Anonymous page-cache hits never reach the router. The
Symfony components Drupal uses are thin layers; nearly all request time runs
inside `HttpKernel`, but not in Symfony's own code.

### Html::load() can't be swapped like a service
HTML parsing (the pure-PHP Masterminds parser) is behind the static methods
`Html::load()`/`Html::serialize()`, called directly from 26 places in core.
Replacing them means overriding a core class at the autoloader, not swapping
a service, which breaks the "behind an interface" rule and is fragile across
core updates. PHP 8.4+ also ships a native C HTML5 parser (`Dom\HTMLDocument`,
Lexbor), so Rust would compete with C there, not with PHP.

## Gotchas

### Debian bookworm's SQLite is too old for Drupal 11
Drupal 11 needs SQLite >= 3.45; bookworm ships 3.40.1 and the installer fails
at `install_verify_requirements`. Fix: base the image on `php:8.3-cli-trixie`
(3.46.1).

### Drupal 11.4's standard profile no longer creates content types
`drush site:install standard` finishes successfully but only installs 137
config objects: there is no `node.type.article`, and `Node::create()` with an
unknown bundle saves without complaint. Rendering such a node fails with
`displaySubmitted() on null`. Content types now come from recipes: apply
`core/recipes/standard`, `article_tags`, `article_comment` and
`page_content_type` with `drush recipe:apply <absolute path>`. Drush rejects
relative recipe paths as "not a directory".

### Drush's SQLite URL takes the first path segment as the host
`--db-url=sqlite://sites/default/files/.ht.sqlite` stored the database as
`default/files/.ht.sqlite`, relative to the current directory. It worked from
Drush but the web server, started elsewhere, saw an empty database and
redirected to `install.php`. Fix: in settings.php use
`$app_root . '/' . $site_path . '/files/.ht.sqlite'`. `DRUPAL_ROOT` is not
defined in settings.php.

### Drupal must be served from the docroot
With `php -S -t web` started from `drupal/`, Twig failed with `Template
"core/modules/node/templates/..." is not defined`: template paths are relative
to the working directory. Start the server from `web/`.

### Shell job control in scripts
`kill %1` does nothing in a non-interactive `sh -c`, and `pkill` isn't in the
PHP image. Leftover servers kept the port, so later benchmark runs hit the
wrong server or hung in `wait`. Fix: `(cd web && exec php -S ...) & SRV=$!`,
then `kill $SRV`, so `$!` is the server's own PID.

### ChainedFastBackend only works if every process uses it
A consistency test "failed" because Drush ran with the plain database backend:
its writes never called `markAsOutdated()`, so the web process kept trusting
its fast copy. Every process (web, Drush, cron) must use the same cache
configuration.

### LMDB handles must not cross fork()
PHP-FPM and `php -S` with `PHP_CLI_SERVER_WORKERS` fork workers from a master.
An LMDB environment opened before `fork()` must not be used, or even closed,
by the child (closing releases the parent's reader slot). The store records
the PID that opened it; `rebar_shared_open()` reopens in a new process and
deliberately leaks (`mem::forget`) the inherited handle. The store is only
ever opened lazily from a request, never at module startup, so the FPM master
never holds one.

### LMDB keys are limited to 511 bytes
Drupal cache IDs can be longer (render cache IDs carry every cache context),
and core's database backend hashes long IDs for the same reason. Keys are
`<bin>\0<cid>`, or `<bin>\0#<sha256(cid)>` when too long; the PHP side keeps
the real cid, so callers never see the hash.

### Docker's /dev/shm is only 64MB
An LMDB map on tmpfs is sparse, but writing past the tmpfs size kills the
process with SIGBUS instead of returning an error. `bin/dev` runs containers
with `--shm-size=1g`; on a real host, make sure the tmpfs is larger than
`$settings['rebar']['shared_size_mb']`.

### default_backend tags outrank $settings['cache']['default']
`CacheFactory::get()` picks, in order: `$settings['cache']['bins'][$bin]`, the
bin's `default_backend` service tag, then `$settings['cache']['default']`.
Core tags `bootstrap`, `config`, `discovery` and `routes` with
`cache.backend.chainedfast`, so setting only the default left those four bins
as APCu in front of the Rust store. Map them explicitly in
`$settings['cache']['bins']`. Locally this went unnoticed because without APCu
ChainedFast falls through to the default backend.

### A PHP extension can be loaded for one FPM pool only
`php_admin_value[extension] = /path/rebar.so` in a pool file loads it in that
pool's workers only (verified via `/proc/<pid>/maps`: 4 of 4 of the Rebar
site's workers, 0 of the stock site's and the shared `www` pool's). That keeps
Rust code away from every other site on a shared server. The CLI needs the
same: `bin/drush` runs `php -d extension=...`. settings.php warns on stderr
when a CLI run lacks the extension, since that process would use a different
cache backend than the web workers.

### Build the production extension in Docker under emulation
`bin/build-ext-linux` builds `rebar.so` for linux/amd64 with the official
`php:8.5` image on an arm64 Mac (Rust build about 40s under emulation, after
a slow first image build). Built against 8.5.10 headers, it loads on the
server's 8.5.4: extensions are compatible across patch releases (same
`API20250925,NTS`). No compiler or Rust toolchain on the server.

### Don't pin memory on a host that is already swapping
Static pools of 4 workers each held about 700MB RSS (83-93MB per worker)
permanently. Both pools now use `pm = ondemand` (max 4, 120s idle timeout),
identically: no memory between benchmarks, and the benchmark warm-up spawns
all workers before measuring.

### Replace a loaded extension by rename, never in place
FPM workers have `rebar.so` memory-mapped. Overwriting the file in place (as
`scp` does) truncates pages under running processes, which can crash them.
`bin/deploy` uploads `rebar.so.new` and `mv`s it over (workers keep
the old inode), then reloads FPM so new workers map the new file.

### Cargo doesn't notice a PHP version change
After moving the dev image from PHP 8.3 to 8.5, `cargo build` reported
"Finished" in 0.07s and kept the 8.3 module (`Module compiled with module
API=20230831`; every test was skipped because the extension didn't load):
ext-php-rs's build script doesn't rerun when PHP changes. Build scripts now
use a cargo target directory per PHP minor version.

### php-config has no --phpapi; and a broken extension writes to stdout
The first per-version target dir used `$(php-config --phpapi)`, which prints
usage text, and cargo then failed with "failed to join paths from
$LD_LIBRARY_PATH" (the colon in it). The fallback `$(php -r ...)` then
captured the stale `rebar.so` startup warning, which PHP prints to stdout. Use
`php -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'`: `-n` skips ini
files and so never loads the extension.

### pgrep -f matches the shell running it
Checking which FPM workers had the extension loaded with
`pgrep -f "php-fpm: pool rebar"` over SSH kept reporting one "worker"
without it, with a new PID each time. It was the `bash` running the check,
whose own command line contains the pattern. It had been explained away as an
old worker exiting after a reload. Filter by process name instead:
`pgrep -x php-fpm8.5`, then match `/proc/<pid>/cmdline`.

### ab counts varying page lengths as failures
Logged-in pages carry per-request form tokens, so `ab` reported "failed"
requests for length mismatches. Use `ab -l`.

### Several extensions in one FPM pool
Repeated `php_admin_value[extension] = ...` lines in a pool file all load
(verified: `rebar.so` and `excimer.so` both mapped in the pool's workers); a later line
does not override an earlier one.

## ext-php-rs notes

- Version 0.15.x. `#[php_function]` plus `wrap_function!` in `#[php_module]`.
- Binary-safe strings: take `BinarySlice<u8>` (zero-copy) and return
  `Binary<u8>`. `&str` requires UTF-8, and serialized PHP can contain any bytes.
- Build arrays with `ZendHashTable::new()` and `insert()`; nested
  `ZBox<ZendHashTable>` values work.
- `static` state lives for the PHP process, so it survives across requests on
  the same worker.
- `ZBox<ZendStr>` is not `IntoZval`. To hand bytes to PHP with a single copy:
  `let mut z = Zval::new(); z.set_zend_string(ZendStr::new(bytes, false));`.
  `Binary::from(vec)` costs a second copy (the `Vec` first).
