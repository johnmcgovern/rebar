<?php

declare(strict_types=1);

namespace Drupal\Tests\drust\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\drust\Cache\RustCacheTagsChecksum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Runs the conformance tests with cache tag counters in Rust, plus extras.
 */
#[Group('drust')]
#[RequiresPhpExtension('drust')]
#[RunTestsInSeparateProcesses]
class RustCacheTagsChecksumTest extends SharedRustBackendTest {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->setSetting('drust', ['shared_path' => static::PATH, 'shared_size_mb' => 64]);
    // What DrustServiceProvider does when $settings['drust']['cache_tags'] is
    // 'shared'; the module itself isn't enabled in these tests.
    $container->getDefinition('cache_tags.invalidator.checksum')
      ->setClass(RustCacheTagsChecksum::class)
      ->setArguments([new Reference('database'), '%app.root%', '%site.path%']);
  }

  /**
   * The key the checksum service stores counters under.
   */
  protected function tagsBin(): string {
    return Settings::getApcuPrefix('drust_backend', $this->root, $this->siteDirectory) . '::cachetags';
  }

  /**
   * The container uses the Rust checksum service.
   */
  public function testServiceIsRust(): void {
    $this->assertInstanceOf(RustCacheTagsChecksum::class, \Drupal::service('cache_tags.invalidator.checksum'));
  }

  /**
   * Invalidations inside a transaction are applied only when it commits.
   */
  public function testInvalidationDelayedUntilCommit(): void {
    $backend = $this->getCacheBackend();
    $backend->set('tagged', 'value', Cache::PERMANENT, ['drust_txn']);
    $before = drust_tags_get(static::PATH, $this->tagsBin(), ['drust_txn'])['drust_txn'];

    $transaction = Database::getConnection()->startTransaction();
    Cache::invalidateTags(['drust_txn']);
    // Not yet applied in the store, but this request must not trust the item.
    $this->assertSame($before, drust_tags_get(static::PATH, $this->tagsBin(), ['drust_txn'])['drust_txn']);
    $this->assertFalse($backend->get('tagged'));
    unset($transaction);

    $this->assertSame($before + 1, drust_tags_get(static::PATH, $this->tagsBin(), ['drust_txn'])['drust_txn']);
    $this->assertFalse($backend->get('tagged'));
  }

  /**
   * Purging starts a new epoch, invalidating everything tagged.
   */
  public function testPurgeInvalidatesTaggedItems(): void {
    $backend = $this->getCacheBackend();
    $backend->set('tagged', 'value', Cache::PERMANENT, ['drust_purge']);
    $backend->set('untagged', 'value');
    $this->assertSame('value', $backend->get('tagged')->data);

    \Drupal::service('cache_tags.invalidator')->purge();
    $this->assertFalse($backend->get('tagged'));
    $this->assertSame('value', $backend->get('untagged')->data);
  }

  /**
   * A tag invalidated by another PHP process invalidates items here.
   */
  public function testCrossProcessInvalidation(): void {
    $backend = $this->getCacheBackend();
    $backend->set('tagged', 'value', Cache::PERMANENT, ['drust_remote']);
    $this->assertSame('value', $backend->get('tagged')->data);

    $script = sprintf('drust_shared_open(%1$s, 64); drust_tags_invalidate(%1$s, %2$s, ["drust_remote"]);',
      var_export(static::PATH, TRUE), var_export($this->tagsBin(), TRUE));
    shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($script));

    // A new request starts with an empty static tag cache.
    \Drupal::service('cache_tags.invalidator.checksum')->reset();
    $this->assertFalse($backend->get('tagged'));
  }

}
