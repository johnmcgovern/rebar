<?php

declare(strict_types=1);

namespace Drupal\Tests\drust\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\drust\Cache\RustBackend;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs the conformance tests against the shared (LMDB) store, plus extras.
 */
#[Group('drust')]
#[RequiresPhpExtension('drust')]
#[RunTestsInSeparateProcesses]
class SharedRustBackendTest extends RustBackendTest {

  /**
   * Store location for the tests, on tmpfs.
   */
  protected const PATH = '/dev/shm/drust-test';

  /**
   * {@inheritdoc}
   */
  protected function createCacheBackend($bin): RustBackend {
    drust_shared_open(static::PATH, 64);
    return new RustBackend($bin, $this->databasePrefix, \Drupal::service('cache_tags.invalidator.checksum'), \Drupal::service(TimeInterface::class), static::PATH);
  }

  /**
   * Cache IDs longer than LMDB's 511 byte key limit are stored and distinct.
   */
  public function testLongCids(): void {
    $backend = $this->getCacheBackend();
    $long_a = str_repeat('a', 2000) . 'A';
    $long_b = str_repeat('a', 2000) . 'B';
    $backend->set($long_a, 'value A');
    $backend->set($long_b, 'value B');
    $this->assertSame('value A', $backend->get($long_a)->data);
    $this->assertSame('value B', $backend->get($long_b)->data);
    $this->assertSame($long_a, $backend->get($long_a)->cid);
    $backend->delete($long_a);
    $this->assertFalse($backend->get($long_a));
    $this->assertSame('value B', $backend->get($long_b)->data);
  }

  /**
   * Writes from another PHP process are visible, and vice versa.
   */
  public function testCrossProcess(): void {
    $backend = $this->getCacheBackend();
    $bin = $this->databasePrefix . '::' . $this->getTestBin();
    $backend->set('from_test', 'written by the test process');

    // A separate PHP process reads our item and writes its own.
    $script = sprintf(
      'drust_shared_open(%s, 64);'
      . '$r = drust_cache_get_multiple(%1$s, %2$s, ["from_test"]);'
      . 'echo unserialize($r["from_test"]["data"]);'
      . 'drust_cache_set(%1$s, %2$s, "from_child", serialize("written by pid " . getmypid()), microtime(TRUE), -1, "", "0");',
      var_export(static::PATH, TRUE), var_export($bin, TRUE),
    );
    $output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($script));
    $this->assertSame('written by the test process', $output);
    $this->assertStringStartsWith('written by pid ', $backend->get('from_child')->data);
    $this->assertNotSame('written by pid ' . getmypid(), $backend->get('from_child')->data);
  }

  /**
   * A full map is flushed and the write retried, instead of failing.
   */
  public function testMapFull(): void {
    $small = static::PATH . '-small';
    drust_shared_open($small, 1);
    $backend = new RustBackend('full', $this->databasePrefix, \Drupal::service('cache_tags.invalidator.checksum'), \Drupal::service(TimeInterface::class), $small);
    $payload = random_bytes(100 * 1024);
    for ($i = 0; $i < 50; $i++) {
      $backend->set("item_$i", $payload);
    }
    $stats = drust_cache_stats($small);
    $this->assertGreaterThan(0, $stats['evictions']);
    $this->assertSame($payload, $backend->get('item_49')->data);
    $this->assertFalse($backend->get('item_0'));
    $backend->removeBin();
  }

}
