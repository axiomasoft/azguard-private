<?php

declare(strict_types=1);

use AzGuard\Changes\ChangePipeline;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Concerns\SubjectWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;

afterEach(fn () => CrmWorld::resetRuntime());

it('P13 rejects an unknown role and a mistyped permission in the change pipeline and stores nothing', function (): void {
    CrmWorld::seed();
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

it('P13 rejects the same typos through $user->guard(\'admin\') and stores nothing', function (): void {
    SubjectWorld::seed();
    SubjectWorld::compile();
    $user = SubjectWorld::member();

    expect(fn () => $user->guard('admin')->grantRole('edtor'))->toThrow(UnknownRoleException::class)
        ->and(fn () => $user->guard('admin')->grantPermission('admin:orders.veiw'))->toThrow(UnknownPermissionException::class)
        ->and($user->guard('admin')->roleGrants())->toBeEmpty()
        ->and($user->guard('admin')->permissionGrants())->toBeEmpty()
        ->and($user->guard('admin')->grantRole('manager')->applied())->toBeTrue()
        ->and($user->guard('admin')->roleGrants()->map(static fn ($grant): string => $grant->roleKey()->full())->all())->toBe(['admin:manager']);
});
