<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use AzGuard\Testing\InteractsWithAzGuard;
use Orchestra\Testbench\TestCase;

/** A test case of an application that uses the testing kit, so that PHPStan analyses the trait it publishes. */
abstract class AnalysedConsumer extends TestCase
{
    use InteractsWithAzGuard;
}
