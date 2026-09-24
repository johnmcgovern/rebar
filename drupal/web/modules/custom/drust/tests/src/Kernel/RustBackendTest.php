<?php

declare(strict_types=1);

namespace Drupal\Tests\drust\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\drust\Cache\RustBackend;
use Drupal\KernelTests\Core\Cache\GenericCacheBackendUnitTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs core's generic cache backend conformance tests against RustBackend.
 */
#[Group('drust')]
#[RequiresPhpExtension('drust')]
#[RunTestsInSeparateProcesses]
class RustBackendTest extends GenericCacheBackendUnitTestBase {

  /**
   * {@inheritdoc}
   */
  protected function createCacheBackend($bin): RustBackend {
    return new RustBackend($bin, $this->databasePrefix, \Drupal::service('cache_tags.invalidator.checksum'), \Drupal::service(TimeInterface::class));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->cacheBackends as $cache_backend) {
      $cache_backend->removeBin();
    }
    parent::tearDown();
  }

}
