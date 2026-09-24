<?php

/**
 * @file
 * drust settings for the local dev site, included from settings.php.
 */

// Drust cache modes (env DRUST_CACHE):
// - 1: all bins live only in the Rust extension (per-process, fastest).
// - chained: Rust in front of the database via ChainedFastBackend (consistent).
// - shared: all bins in Rust shared memory (LMDB), shared by all processes.
if (extension_loaded('drust')) {
  $drust_backends = [
    '1' => 'cache.backend.drust',
    'chained' => 'cache.backend.drust_chained',
    'shared' => 'cache.backend.drust_shared',
  ];
  if ($drust_backend = $drust_backends[getenv('DRUST_CACHE') ?: ''] ?? NULL) {
    $settings['cache']['default'] = $drust_backend;
    // Bins tagged default_backend: cache.backend.chainedfast outrank the default.
    foreach (['bootstrap', 'config', 'discovery', 'routes'] as $bin) {
      $settings['cache']['bins'][$bin] = $drust_backend;
    }
  }
}
