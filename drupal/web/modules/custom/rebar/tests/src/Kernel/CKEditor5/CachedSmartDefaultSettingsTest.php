<?php

declare(strict_types=1);

namespace Drupal\Tests\rebar\Kernel\CKEditor5;

use Drupal\Tests\ckeditor5\Kernel\SmartDefaultSettingsTest as CoreTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Core's SmartDefaultSettingsTest, with the caching CKEditor 5 plugin manager.
 */
#[Group('rebar')]
#[RunTestsInSeparateProcesses]
class CachedSmartDefaultSettingsTest extends CoreTest {

  use CachedPluginManagerTestTrait;

}
