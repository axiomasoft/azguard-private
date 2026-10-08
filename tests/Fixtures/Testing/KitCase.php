<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use AzGuard\Testing\InteractsWithAzGuard;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

/**
 * The test case of an application that uses the kit, written as a class (the Pest tests of the kit use the same two
 * traits):  the AzGuard tables are migrated for every test and the database is
 * not wrapped in a transaction, because the engine reads authority only outside a transaction it did not open.
 */
abstract class KitCase extends TestCase
{
    use DatabaseMigrations;
    use InteractsWithAzGuard;
}
