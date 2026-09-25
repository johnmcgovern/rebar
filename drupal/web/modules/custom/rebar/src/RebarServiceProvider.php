<?php

namespace Drupal\rebar;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\Site\Settings;
use Drupal\rebar\Cache\RustCacheTagsChecksum;
use Drupal\rebar\CKEditor5\CachedCKEditor5PluginManager;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Swaps core services for faster ones, as configured in settings.php.
 *
 * The choice is compiled into the container, so it must only depend on
 * settings, never on whether the current process has the extension loaded:
 * a Drush run without it would otherwise compile a container the web workers
 * then use. A process without the extension fails loudly instead.
 */
class RebarServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $settings = Settings::get('rebar', []);
    if (($settings['cache_tags'] ?? NULL) === 'shared') {
      $container->getDefinition('cache_tags.invalidator.checksum')
        ->setClass(RustCacheTagsChecksum::class)
        ->setArguments([new Reference('database'), '%app.root%', '%site.path%']);
    }
    if (!empty($settings['ckeditor5_cache']) && $container->hasDefinition('plugin.manager.ckeditor5.plugin')) {
      $container->getDefinition('plugin.manager.ckeditor5.plugin')
        ->setClass(CachedCKEditor5PluginManager::class);
    }
  }

}
