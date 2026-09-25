<?php

namespace Drupal\rebar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rebar\Cache\RustBackendFactory;
use Drupal\Core\StringTranslation\ByteSizeMarkup;

/**
 * Shows what the Rust extension's cache stores hold.
 */
class StatusController extends ControllerBase {

  /**
   * Renders extension and cache store statistics.
   */
  public function status(): array {
    if (!extension_loaded('rebar')) {
      return ['#markup' => $this->t('The rebar PHP extension is not loaded.')];
    }
    $build = [
      'hello' => ['#markup' => '<p>' . rebar_hello('Drupal') . '</p>'],
      '#cache' => ['max-age' => 0],
    ];
    $stores = ['local' => $this->t('Local store (this PHP process)')];
    try {
      $path = RustBackendFactory::openSharedStore();
      $stores[$path] = $this->t('Shared store @path (all PHP processes)', ['@path' => $path]);
    }
    catch (\Exception $e) {
      $build['shared_error'] = ['#markup' => '<p>' . $e->getMessage() . '</p>'];
    }
    foreach ($stores as $store => $title) {
      $build[$store] = $this->storeTable(rebar_cache_stats($store), $title);
    }
    return $build;
  }

  /**
   * Builds a table of one store's bins.
   */
  protected function storeTable(array $stats, $title): array {
    $lookups = $stats['hits'] + $stats['misses'];
    $summary = $this->t('PHP process @pid: @hits hits, @misses misses (@rate% hit rate), @writes writes', [
      '@pid' => $stats['pid'],
      '@hits' => $stats['hits'],
      '@misses' => $stats['misses'],
      '@rate' => $lookups ? round(100 * $stats['hits'] / $lookups, 1) : 0,
      '@writes' => $stats['writes'],
    ]);
    if (isset($stats['used_bytes'])) {
      $summary .= ' ' . $this->t('Map in use: @used, full flushes: @evictions.', [
        '@used' => ByteSizeMarkup::create($stats['used_bytes']),
        '@evictions' => $stats['evictions'],
      ]);
    }
    $rows = [];
    ksort($stats['bins']);
    foreach ($stats['bins'] as $bin => $b) {
      $rows[] = [substr($bin, strrpos($bin, '::') + 2), $b['items'], ByteSizeMarkup::create($b['bytes'])];
    }
    return [
      '#type' => 'details',
      '#title' => $title,
      '#open' => TRUE,
      'summary' => ['#markup' => '<p>' . $summary . '</p>'],
      'bins' => [
        '#type' => 'table',
        '#header' => [$this->t('Bin'), $this->t('Items'), $this->t('Payload')],
        '#rows' => $rows,
        '#empty' => $this->t('Empty.'),
      ],
    ];
  }

}
