<?php

declare(strict_types=1);

namespace Drupal\Tests\rebar\Kernel\CKEditor5;

use Drupal\Tests\ckeditor5\Kernel\ValidatorsTest as CoreTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Core's ValidatorsTest, with the caching CKEditor 5 plugin manager.
 */
#[Group('rebar')]
#[RunTestsInSeparateProcesses]
class CachedValidatorsTest extends CoreTest {

  use CachedPluginManagerTestTrait;

}
