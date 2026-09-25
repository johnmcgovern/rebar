<?php

namespace Drupal\rebar\CKEditor5;

use Drupal\ckeditor5\Plugin\CKEditor5PluginManager;
use Drupal\Core\Cache\Cache;
use Drupal\editor\EditorInterface;

/**
 * CKEditor 5 plugin manager that caches which plugins an editor enables.
 *
 * getEnabledDefinitions() runs on every request that renders an editor (for
 * logged-in users, e.g. every node page with a comment form), and twice per
 * editor: via getLibraries() and getJSSettings(). It evaluates plugin
 * conditions and HTML restrictions and parses allowed HTML: about 5ms per
 * request on drupal02, a quarter of the node page. The result depends only on
 * configuration, so it is cached. The per-request parts (dynamic plugin config
 * with CSRF tokens, hook_editor_js_settings_alter()) still run every time.
 *
 * The cache key is a hash of the editor's and text format's actual contents,
 * not their IDs: the CKEditor 5 admin form validates unsaved, modified pairs.
 * Only plugin IDs are cached; definitions come from getDefinitions() as usual.
 *
 * Enabled with $settings['rebar']['ckeditor5_cache'] = TRUE (see
 * RebarServiceProvider).
 */
class CachedCKEditor5PluginManager extends CKEditor5PluginManager {

  /**
   * Cache tag for every cached result; invalidated with the definitions.
   */
  const CACHE_TAG = 'rebar_ckeditor5_enabled_definitions';

  /**
   * Enabled plugin IDs by cache ID, for the rest of this request.
   *
   * @var string[][]
   */
  protected array $enabledPluginIds = [];

  /**
   * {@inheritdoc}
   */
  public function getEnabledDefinitions(EditorInterface $editor): array {
    $format = $editor->getFilterFormat();
    // Without a format there is nothing stable to key on; don't cache.
    if ($format === NULL) {
      return parent::getEnabledDefinitions($editor);
    }
    $cid = 'rebar:ckeditor5:enabled:' . hash('xxh128', serialize([$editor->toArray(), $format->toArray()]));
    if (!isset($this->enabledPluginIds[$cid])) {
      if ($cached = $this->cacheBackend->get($cid)) {
        $this->enabledPluginIds[$cid] = $cached->data;
      }
      else {
        $this->enabledPluginIds[$cid] = array_keys(parent::getEnabledDefinitions($editor));
        $this->cacheBackend->set($cid, $this->enabledPluginIds[$cid], Cache::PERMANENT, [static::CACHE_TAG]);
      }
    }
    $definitions = $this->getDefinitions();
    $enabled = [];
    foreach ($this->enabledPluginIds[$cid] as $plugin_id) {
      // A definition removed since the result was cached means the cached
      // result is stale too.
      if (!isset($definitions[$plugin_id])) {
        $this->clearCachedDefinitions();
        return $this->getEnabledDefinitions($editor);
      }
      $enabled[$plugin_id] = $definitions[$plugin_id];
    }
    return $enabled;
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions() {
    parent::clearCachedDefinitions();
    $this->enabledPluginIds = [];
    Cache::invalidateTags([static::CACHE_TAG]);
  }

}
