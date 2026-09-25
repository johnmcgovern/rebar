<?php

declare(strict_types=1);

namespace Drupal\Tests\rebar\Kernel\CKEditor5;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\rebar\CKEditor5\CachedCKEditor5PluginManager;

/**
 * Runs a core CKEditor 5 kernel test with the caching plugin manager.
 */
trait CachedPluginManagerTestTrait {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // What RebarServiceProvider does when ckeditor5_cache is on; the rebar
    // module itself isn't enabled in these tests.
    if ($container->hasDefinition('plugin.manager.ckeditor5.plugin')) {
      $container->getDefinition('plugin.manager.ckeditor5.plugin')->setClass(CachedCKEditor5PluginManager::class);
    }
  }

  /**
   * The core test really ran against the caching manager.
   */
  public function testUsesCachedManager(): void {
    $this->assertInstanceOf(CachedCKEditor5PluginManager::class, $this->container->get('plugin.manager.ckeditor5.plugin'));
  }

}
