<?php

namespace Drupal\drust\Cache;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;

/**
 * Creates cache bins stored in the drust Rust extension.
 *
 * Enable in settings.php with one of:
 * @code
 * // Private to each PHP process.
 * $settings['cache']['default'] = 'cache.backend.drust';
 * // Shared by all PHP processes on the machine.
 * $settings['cache']['default'] = 'cache.backend.drust_shared';
 * // Optional, for the shared store (defaults shown). The path should be on
 * // tmpfs, and the tmpfs must be larger than the map size.
 * $settings['drust']['shared_path'] = '/dev/shm/drust';
 * $settings['drust']['shared_size_mb'] = 256;
 * @endcode
 */
class RustBackendFactory implements CacheFactoryInterface {

  /**
   * Prefix separating this site's bins from others in the same store.
   */
  protected string $sitePrefix;

  /**
   * The store bins are created in: 'local', or the path of a shared store.
   */
  protected string $store;

  public function __construct(
    string $root,
    string $site_path,
    protected CacheTagsChecksumInterface $checksumProvider,
    protected TimeInterface $time,
    string $store = 'local',
  ) {
    $this->sitePrefix = Settings::getApcuPrefix('drust_backend', $root, $site_path);
    $this->store = $store === 'shared' ? static::openSharedStore() : $store;
  }

  /**
   * Opens the shared store configured in settings.php and returns its path.
   */
  public static function openSharedStore(): string {
    $settings = Settings::get('drust', []);
    $path = $settings['shared_path'] ?? '/dev/shm/drust';
    drust_shared_open($path, $settings['shared_size_mb'] ?? 256);
    return $path;
  }

  /**
   * {@inheritdoc}
   */
  public function get($bin) {
    return new RustBackend($bin, $this->sitePrefix, $this->checksumProvider, $this->time, $this->store);
  }

}
