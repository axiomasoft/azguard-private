<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Hooks;

use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\Testing\Contracts\HookContractTests;
use AzGuard\Tests\Fixtures\Contracts\DenyEditHook;
use AzGuard\Tests\Fixtures\Contracts\ObservingAfterHook;
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

    protected function azguardAfterHook(): AfterHook|string|null
    {
        return ObservingAfterHook::class;
    }

    protected function azguardChangePipe(): ChangePipe|string|null
    {
        return new PassThroughPipe;
    }
}
