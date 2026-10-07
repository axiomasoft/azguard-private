<?php

declare(strict_types=1);

use AzGuard\Changes\ChangeStatus;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Tests\Fixtures\Concerns\AdminPermission;
use AzGuard\Tests\Fixtures\Concerns\CabinetPermission;
use AzGuard\Tests\Fixtures\Concerns\Member;
use AzGuard\Tests\Fixtures\Concerns\Roles\ManagerRole;
use AzGuard\Tests\Fixtures\Concerns\SubjectWorld;

/*
 * V70: the recipes of "coming from Spatie Permission" work on the model in the default panel and through guard().
 * Panels: admin (default, prefix backoffice) and cabinet, both without tenants, each with its own DatabaseSource.
 */

beforeEach(function (): void {
    SubjectWorld::seed();
    SubjectWorld::compile();
});

/**
 * @return array<string, Closure(Member): (Member|SubjectAccess)>
 */
function recipeTargets(): array
{
    return [
        'the model in the default panel' => static fn (Member $member): Member => $member,
        'guard(admin)' => static fn (Member $member): SubjectAccess => $member->guard('admin'),
        'azguard()->guard(admin)' => static fn (Member $member): SubjectAccess => $member->azguard()->guard('admin'),
    ];
}

it('grants, checks and revokes roles like assignRole/hasRole/removeRole', function (string $target): void {
    $member = SubjectWorld::member();
    $subject = recipeTargets()[$target]($member);

    expect($subject->grantRole('manager')->status)->toBe(ChangeStatus::Applied)
        ->and($subject->grantRole('manager')->status)->toBe(ChangeStatus::Unchanged)
        ->and($subject->hasRole('manager'))->toBeTrue()
        ->and($subject->hasRole(['support', 'manager']))->toBeTrue()
        ->and($subject->hasAnyRole(['support', 'manager']))->toBeTrue()
        ->and($subject->hasAllRoles(['support', 'manager']))->toBeFalse()
        ->and($subject->hasRole(ManagerRole::class))->toBeTrue()
        ->and($subject->roleNames()->all())->toBe(['manager'])
        ->and($subject->hasPermission('orders.view'))->toBeTrue()
        ->and($subject->hasPermission(AdminPermission::Update))->toBeTrue()
        ->and($subject->hasPermission('backoffice.orders.update'))->toBeTrue()
        ->and($subject->hasPermission('admin:orders.refund'))->toBeFalse()
        ->and($subject->hasAnyPermission(['orders.refund', 'orders.view']))->toBeTrue()
        ->and($subject->hasAllPermissions(['orders.refund', 'orders.view']))->toBeFalse()
        ->and($subject->hasAllPermissions(['orders.update', 'orders.view']))->toBeTrue()
        ->and($subject->revokeRole('manager')->status)->toBe(ChangeStatus::Applied)
        ->and($subject->hasRole('manager'))->toBeFalse()
        ->and($subject->hasPermission('orders.view'))->toBeFalse()
        ->and(SubjectWorld::roleRows())->toBe([]);
})->with(array_keys(recipeTargets()));

it('syncs roles like syncRoles', function (string $target): void {
    $subject = recipeTargets()[$target](SubjectWorld::member());
    $subject->grantRole(['manager', 'auditor']);

    expect($subject->syncRoles(['support', 'manager'])->applied())->toBeTrue()
        ->and($subject->roleNames()->sort()->values()->all())->toBe(['manager', 'support'])
        ->and(SubjectWorld::roleRows())->toBe(['admin|manager|1|global|manual', 'admin|support|1|global|manual'])
        ->and($subject->syncRoles(['support', 'manager'])->status)->toBe(ChangeStatus::Unchanged)
        ->and($subject->syncRoles([])->applied())->toBeTrue()
        ->and($subject->roleNames()->all())->toBe([]);
})->with(array_keys(recipeTargets()));

it('grants, syncs and revokes direct permissions like givePermissionTo/revokePermissionTo', function (string $target): void {
    $subject = recipeTargets()[$target](SubjectWorld::member());

    expect($subject->grantPermission([AdminPermission::Refund, 'orders.view'])->applied())->toBeTrue()
        ->and(SubjectWorld::permissionRows())->toBe(['admin|orders.refund|1|global|manual', 'admin|orders.view|1|global|manual'])
        ->and($subject->hasPermission('orders.refund'))->toBeTrue()
        ->and($subject->permissionNames()->all())->toBe(['backoffice.orders.refund', 'backoffice.orders.view'])
        ->and($subject->syncPermissions(['orders.*'])->applied())->toBeTrue()
        ->and(SubjectWorld::permissionRows())->toBe(['admin|orders.*|1|global|manual'])
        ->and($subject->hasAllPermissions(['orders.view', 'orders.update', 'orders.refund']))->toBeTrue()
        ->and($subject->hasPermission('users.delete'))->toBeFalse()
        ->and($subject->revokePermission('orders.*')->applied())->toBeTrue()
        ->and($subject->hasPermission('orders.view'))->toBeFalse()
        ->and(SubjectWorld::permissionRows())->toBe([]);
})->with(array_keys(recipeTargets()));

it('runs the same recipes in a panel that is not the default one through guard()', function (): void {
    $member = SubjectWorld::member();

    expect($member->guard('cabinet')->grantRole('editor')->applied())->toBeTrue()
        ->and($member->guard('cabinet')->hasRole('editor'))->toBeTrue()
        ->and($member->hasRole('editor', guard: 'cabinet'))->toBeTrue()
        ->and($member->hasPermission(CabinetPermission::View))->toBeTrue()
        ->and($member->hasPermission('invoices.view', guard: 'cabinet'))->toBeTrue()
        ->and($member->guard('cabinet')->roleNames()->all())->toBe(['editor'])
        ->and($member->roleNames()->all())->toBe([])
        ->and($member->guard('cabinet')->grantPermission('invoices.pay')->applied())->toBeTrue()
        ->and($member->guard('cabinet')->permissionNames()->all())->toBe(['cabinet.invoices.pay', 'cabinet.invoices.view'])
        ->and($member->guard('cabinet')->revokeRole('editor')->applied())->toBeTrue()
        ->and(SubjectWorld::roleRows())->toBe([])
        ->and(SubjectWorld::permissionRows())->toBe(['cabinet|invoices.pay|1|global|manual']);
});

it('keeps an empty list of roles or permissions false for any and all', function (): void {
    $member = SubjectWorld::member();
    $member->grantRole('manager');

    expect($member->hasAnyRole([]))->toBeFalse()
        ->and($member->hasAllRoles([]))->toBeFalse()
        ->and($member->hasAnyPermission([]))->toBeFalse()
        ->and($member->hasAllPermissions([]))->toBeFalse();
});
