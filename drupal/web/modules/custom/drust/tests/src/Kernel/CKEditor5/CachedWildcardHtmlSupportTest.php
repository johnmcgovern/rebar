<?php

declare(strict_types=1);

namespace Drupal\Tests\drust\Kernel\CKEditor5;

use Drupal\Tests\ckeditor5\Kernel\WildcardHtmlSupportTest as CoreTest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Core's WildcardHtmlSupportTest, with the caching CKEditor 5 plugin manager.
 */
#[Group('drust')]
#[RunTestsInSeparateProcesses]
class CachedWildcardHtmlSupportTest extends CoreTest {

  use CachedPluginManagerTestTrait;

}
