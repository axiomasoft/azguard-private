<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Console\Generators;

use AzGuard\Testing\Contracts\HookContractTests;
use AzGuard\Tests\Fixtures\Console\GeneratesComponents;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

/** The change pipe `azguard:make:pipe` writes passes the contract suite of hooks and pipes. */
final class GeneratedPipeContractTest extends TestCase
{
    use DatabaseMigrations;
    use GeneratesComponents;
    use HookContractTests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generate('azguard:make:pipe', ['name' => 'RequireReason', '--panel' => 'Admin']);
    }

    protected function azguardChangePipe(): object|string|null
    {
        return $this->generatedClass('Admin\Changes\RequireReason');
    }
}
