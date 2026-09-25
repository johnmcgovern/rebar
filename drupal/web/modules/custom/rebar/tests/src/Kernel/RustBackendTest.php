<?php

declare(strict_types=1);

namespace Drupal\Tests\rebar\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\rebar\Cache\RustBackend;
use Drupal\KernelTests\Core\Cache\GenericCacheBackendUnitTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs core's generic cache backend conformance tests against RustBackend.
 */
#[Group('rebar')]
#[RequiresPhpExtension('rebar')]
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
