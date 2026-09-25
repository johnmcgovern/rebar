
// Rebar: Rust-backed services from the rebar PHP extension, which only the
// site's PHP-FPM pool and bin/drush load.
$settings['rebar'] = [
  // Shared LMDB store on tmpfs, used by every PHP process of this site.
  'shared_path' => '/dev/shm/rebar-example',
  'shared_size_mb' => 256,
  // Cache tag invalidation counters in the shared store. This is compiled
  // into the service container, so it is set whether or not the current
  // process loaded the extension: one without it fails loudly rather than
  // quietly using different counters than the web workers.
  'cache_tags' => 'shared',
  // Cache which CKEditor 5 plugins each editor enables (PHP-only; see
  // CachedCKEditor5PluginManager). Also compiled into the container.
  'ckeditor5_cache' => TRUE,
];
if (extension_loaded('rebar')) {
  $settings['cache']['default'] = 'cache.backend.rebar_shared';
  // These bins are tagged default_backend: cache.backend.chainedfast (APCu in
  // front of the default), and that tag outranks the default above.
  foreach (['bootstrap', 'config', 'discovery', 'routes'] as $bin) {
    $settings['cache']['bins'][$bin] = 'cache.backend.rebar_shared';
  }
}
elseif (PHP_SAPI === 'cli') {
  fwrite(STDERR, "WARNING: rebar extension not loaded; use bin/drush so caches stay consistent.\n");
}
