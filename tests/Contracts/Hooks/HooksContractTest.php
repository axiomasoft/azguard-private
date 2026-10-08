<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Hooks;

use AzGuard\Testing\Contracts\HookContractTests;
use AzGuard\Tests\Fixtures\Contracts\DenyEditHook;
use AzGuard\Tests\Fixtures\Contracts\PassThroughPipe;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

final class HooksContractTest extends TestCase
{
    use DatabaseMigrations;
    use HookContractTests;

    protected function azguardBeforeHook(): string
    {
        return DenyEditHook::class;
    }

    protected function azguardChangePipe(): object|string|null
    {
        return new PassThroughPipe;
    }
}
