<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Contracts\Sources\Source;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

final class DatabaseSourceContractTest extends TestCase
{
    use DatabaseMigrations;
    use SourceContractTests;

    protected function azguardSource(): Source
    {
        return DatabaseSource::make();
    }
}
