<?php

namespace Drupal\drust\Cache;

use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsChecksumPreloadInterface;
use Drupal\Core\Cache\CacheTagsChecksumTrait;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\CacheTagsPurgeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;

/**
 * Cache tag invalidation counters kept in the drust shared (LMDB) store.
 *
 * Replaces \Drupal\Core\Cache\DatabaseCacheTagsChecksum. Core's trait still
 * does the per-request caching, preloading and delaying of invalidations until
 * the database transaction commits; only the counters move out of the
 * {cachetags} table. Every counter is offset by the store's random epoch, so
 * a flushed or wiped store can never make stale items look valid again.
 *
 * Enable with $settings['drust']['cache_tags'] = 'shared' (see
 * DrustServiceProvider).
 */
class RustCacheTagsChecksum implements CacheTagsChecksumInterface, CacheTagsInvalidatorInterface, CacheTagsChecksumPreloadInterface, CacheTagsPurgeInterface {

  use CacheTagsChecksumTrait;

  /**
   * The path of the shared store holding the counters.
   */
  protected string $store;

  /**
   * The key the counters are stored under: "<site prefix>::cachetags".
   */
  protected string $bin;

  public function __construct(
    protected Connection $connection,
    string $root,
    string $site_path,
  ) {
    $this->store = RustBackendFactory::openSharedStore();
    $this->bin = Settings::getApcuPrefix('drust_backend', $root, $site_path) . '::cachetags';
  }

  /**
   * {@inheritdoc}
   */
  protected function getTagInvalidationCounts(array $tags) {
    return drust_tags_get($this->store, $this->bin, array_values($tags));
  }

  /**
   * {@inheritdoc}
   */
  protected function doInvalidateTags(array $tags) {
    drust_tags_invalidate($this->store, $this->bin, array_values($tags));
  }

  /**
   * {@inheritdoc}
   */
  protected function getDatabaseConnection() {
    return $this->connection;
  }

  /**
   * {@inheritdoc}
   */
  public function purge(): void {
    drust_tags_purge($this->store, $this->bin);
    $this->reset();
  }

}
