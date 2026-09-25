<?php

/**
 * @file
 * Rebar settings for the local dev site, included from settings.php.
 */

// Rebar cache modes (env REBAR_CACHE):
// - 1: all bins live only in the Rust extension (per-process, fastest).
// - chained: Rust in front of the database via ChainedFastBackend (consistent).
// - shared: all bins in Rust shared memory (LMDB), shared by all processes.
if (extension_loaded('rebar')) {
  $rebar_backends = [
    '1' => 'cache.backend.rebar',
    'chained' => 'cache.backend.rebar_chained',
    'shared' => 'cache.backend.rebar_shared',
  ];
  if ($rebar_backend = $rebar_backends[getenv('REBAR_CACHE') ?: ''] ?? NULL) {
    $settings['cache']['default'] = $rebar_backend;
    // Bins tagged default_backend: cache.backend.chainedfast outrank the default.
    foreach (['bootstrap', 'config', 'discovery', 'routes'] as $bin) {
      $settings['cache']['bins'][$bin] = $rebar_backend;
    }
  }
}
