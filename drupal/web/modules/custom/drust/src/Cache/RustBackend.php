<?php

namespace Drupal\drust\Cache;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsChecksumPreloadInterface;

/**
 * Cache backend whose storage lives in the drust Rust extension.
 *
 * Modelled on \Drupal\Core\Cache\ApcuBackend. Items live in one of the
 * extension's stores: 'local' (memory of this PHP process) or a shared LMDB
 * store, named by its path, shared by every PHP process on the machine. Cache tags are
 * validated through the checksum provider. Payloads are PHP-serialized here
 * and are opaque bytes to Rust.
 */
class RustBackend implements CacheBackendInterface {

  /**
   * The key Rust stores this bin under: "<site prefix>::<bin>".
   */
  protected string $binKey;

  public function __construct(
    protected string $bin,
    string $site_prefix,
    protected CacheTagsChecksumInterface $checksumProvider,
    protected TimeInterface $time,
    protected string $store = 'local',
  ) {
    $this->binKey = $site_prefix . '::' . $bin;
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid, $allow_invalid = FALSE) {
    $cids = [$cid];
    $items = $this->getMultiple($cids, $allow_invalid);
    return $items[$cid] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $cids = array_values($cids);
    $result = drust_cache_get_multiple($this->store, $this->binKey, array_map('strval', $cids));
    $cache = [];
    if ($result) {
      if ($this->checksumProvider instanceof CacheTagsChecksumPreloadInterface) {
        $tags_for_preload = [];
        foreach ($result as $raw) {
          if ($raw['tags'] !== '') {
            $tags_for_preload[] = explode(' ', $raw['tags']);
          }
        }
        $this->checksumProvider->registerCacheTagsForPreload(array_merge(...$tags_for_preload));
      }
      foreach ($result as $cid => $raw) {
        if ($item = $this->prepareItem((string) $cid, $raw, $allow_invalid)) {
          $cache[$item->cid] = $item;
        }
      }
    }
    $cids = array_values(array_diff($cids, array_keys($cache)));
    return $cache;
  }

  /**
   * Turns a raw item from Rust into a cache object, or FALSE if invalid.
   */
  protected function prepareItem(string $cid, array $raw, bool $allow_invalid): object|false {
    $item = (object) [
      'cid' => $cid,
      'data' => unserialize($raw['data']),
      'created' => $raw['created'],
      'expire' => $raw['expire'],
      'tags' => $raw['tags'] === '' ? [] : explode(' ', $raw['tags']),
      'checksum' => $raw['checksum'],
    ];
    $item->valid = $item->expire == Cache::PERMANENT || $item->expire >= $this->time->getRequestTime();
    if (!$this->checksumProvider->isValid($item->checksum, $item->tags)) {
      $item->valid = FALSE;
    }
    if (!$allow_invalid && !$item->valid) {
      return FALSE;
    }
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []) {
    assert(Inspector::assertAllStrings($tags), 'Cache tags must be strings.');
    $tags = array_unique($tags);
    drust_cache_set(
      $this->store,
      $this->binKey,
      (string) $cid,
      serialize($data),
      round(microtime(TRUE), 3),
      (int) $expire,
      implode(' ', $tags),
      (string) $this->checksumProvider->getCurrentChecksum($tags),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $items = []) {
    foreach ($items as $cid => $item) {
      $this->set($cid, $item['data'], $item['expire'] ?? Cache::PERMANENT, $item['tags'] ?? []);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid) {
    drust_cache_delete_multiple($this->store, $this->binKey, [(string) $cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $cids) {
    drust_cache_delete_multiple($this->store, $this->binKey, array_map('strval', array_values($cids)));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    drust_cache_delete_all($this->store, $this->binKey);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid) {
    $this->invalidateMultiple([$cid]);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateMultiple(array $cids) {
    drust_cache_invalidate_multiple($this->store, $this->binKey, array_map('strval', array_values($cids)), $this->time->getRequestTime());
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll() {
    @trigger_error("CacheBackendInterface::invalidateAll() is deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Use CacheBackendInterface::deleteAll() or cache tag invalidation instead. See https://www.drupal.org/node/3500622", E_USER_DEPRECATED);
    drust_cache_invalidate_all($this->store, $this->binKey, $this->time->getRequestTime());
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    drust_cache_garbage_collection($this->store, $this->binKey, $this->time->getRequestTime());
  }

  /**
   * {@inheritdoc}
   */
  public function removeBin() {
    drust_cache_delete_all($this->store, $this->binKey);
  }

}
