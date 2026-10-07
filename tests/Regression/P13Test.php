<?php

declare(strict_types=1);

use AzGuard\Changes\ChangePipeline;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;

beforeEach(fn () => CrmWorld::seed());
afterEach(fn () => CrmWorld::resetRuntime());

it('P13 rejects an unknown role and a mistyped permission in the change pipeline and stores nothing', function (): void {
    $panel = ChangeWorld::panel();
    $pipeline = app(ChangePipeline::class);
    $before = [ChangeWorld::rows('role'), ChangeWorld::rows('permission'), ChangeWorld::version()];

    expect(fn () => $pipeline->grant($panel, ChangeWorld::tenant(), ChangeWorld::user(2), $pipeline->role($panel, 'edtor'), ChangeWorld::project(1)))
        ->toThrow(UnknownRoleException::class)
        ->and(fn () => $pipeline->grant($panel, ChangeWorld::tenant(), ChangeWorld::user(2), $pipeline->permission($panel, 'crm:clients.veiw'), ChangeWorld::project()))
        ->toThrow(UnknownPermissionException::class)
        ->and([ChangeWorld::rows('role'), ChangeWorld::rows('permission'), ChangeWorld::version()])->toBe($before)
        ->and(ChangeWorld::rows('permission'))->toBe([])
        ->and($pipeline->grant($panel, ChangeWorld::tenant(), ChangeWorld::user(2), $pipeline->role($panel, 'analyst'), ChangeWorld::project(1))->applied())->toBeTrue();
});
