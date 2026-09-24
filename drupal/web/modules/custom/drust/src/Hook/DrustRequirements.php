<?php

declare(strict_types=1);

namespace Drupal\drust\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Status report entry for the drust PHP extension.
 */
class DrustRequirements {

  use StringTranslationTrait;

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $loaded = extension_loaded('drust');
    $needed = (Settings::get('drust', [])['cache_tags'] ?? NULL) === 'shared';
    return [
      'drust_extension' => [
        'title' => $this->t('Drust PHP extension'),
        'value' => $loaded ? drust_hello('Drupal') : $this->t('Not loaded'),
        'severity' => $loaded ? RequirementSeverity::OK : ($needed ? RequirementSeverity::Error : RequirementSeverity::Warning),
        'description' => $loaded ? NULL : $this->t('Build drust.so and load it for every PHP process of this site; see INSTALL.md. Without it, the Rust cache and cache tag services are unavailable.'),
      ],
    ];
  }

}
