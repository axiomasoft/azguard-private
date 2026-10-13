<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Consumer;

use Acme\Consumer\ConsumerBeforeHook;
use Acme\Consumer\ConsumerPipe;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\Testing\Contracts\HookContractTests;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

require_once dirname(__DIR__, 2).'/Fixtures/Modules/Consumer/autoload.php';

/** I8: the before hook and the change pipe of a package outside `AzGuard\` pass the hook suite. */
final class ConsumerHooksContractTest extends TestCase
{
    use DatabaseMigrations;
    use HookContractTests;

    protected function azguardBeforeHook(): string
    {
        return ConsumerBeforeHook::class;
    }

    protected function azguardChangePipe(): ChangePipe
    {
        return new ConsumerPipe;
    }
}
