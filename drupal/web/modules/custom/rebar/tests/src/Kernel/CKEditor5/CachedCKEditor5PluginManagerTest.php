<?php

declare(strict_types=1);

namespace Drupal\Tests\rebar\Kernel\CKEditor5;

use Drupal\Tests\ckeditor5\Kernel\CKEditor5PluginManagerTest as CoreTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Core's CKEditor5PluginManagerTest, with the caching CKEditor 5 plugin manager.
 */
#[Group('rebar')]
#[RunTestsInSeparateProcesses]
class CachedCKEditor5PluginManagerTest extends CoreTest {

  use CachedPluginManagerTestTrait;

}
