<?php

declare(strict_types=1);

use AzGuard\Changes\ChangeStatus;
use AzGuard\Events\RoleGranted;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => CrmWorld::seed());
afterEach(fn () => CrmWorld::resetRuntime());

it('P11 emits one event after the commit for a change and none for a repeat without a difference', function (): void {
    $panel = ChangeWorld::panel();
    $levels = [];
    Event::listen(RoleGranted::class, function () use (&$levels): void {
        $levels[] = DB::connection(CrmWorld::storage()->connectionName())->transactionLevel();
    });

    $first = ChangeWorld::grant($panel, 'analyst', 2, 1);
    $again = ChangeWorld::grant($panel, 'analyst', 2, 1);

    expect($first->status)->toBe(ChangeStatus::Applied)
        ->and($again->status)->toBe(ChangeStatus::Unchanged)
        ->and($levels)->toBe([0]);
});
