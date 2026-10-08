<?php

declare(strict_types=1);

use AzGuard\Concerns\SubjectAccess;
use AzGuard\Concerns\SubjectPanels;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\WithinContext;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;

/*
 * V87, V38, V39, V103: wrappers are immutable values. Selecting a tenant, an origin or a panel returns a new wrapper;
 * nothing is written into the model, the request panel or the current scope, so another tab, request, job or fiber
 * keeps its own answer. A wrapper without a tenant reads the current tenant of the moment it runs, never one it saw.
 */

beforeEach(function (): void {
    CrmWorld::seed();
    ChangeWorld::panel();
});
afterEach(fn () => CrmWorld::resetRuntime());

it('declares the wrappers final and readonly', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
})->with([SubjectAccess::class, SubjectPanels::class]);

it('keeps two tabs of one model independent: A and B answer for their own tenant', function (): void {
    $anna = User::query()->findOrFail(1);
    $attributes = $anna->getAttributes();
    $crm = $anna->guard('crm');
    $a = $crm->inTenant(Organization::query()->findOrFail(1));
    $b = $crm->inTenant(TenantRef::of('crm.organization', 2));

    expect($a)->not->toBe($crm)->and($b)->not->toBe($a)
        ->and($a->scope()->tenant->key())->toBe('crm.organization:1')
        ->and($b->scope()->tenant->key())->toBe('crm.organization:2')
        ->and(fn () => $crm->scope())->toThrow(TenantRequiredException::class)
        ->and($a->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(1)))->toBeTrue()
        ->and($b->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(5)))->toBeFalse()
        ->and($b->hasPermission(ClientPermission::View, on: Client::query()->findOrFail(5)))->toBeTrue()
        ->and($a->roleNames(on: AssignmentScopeRef::of('crm.project', 1))->all())->toBe(['seller'])
        ->and($b->roleNames(on: AssignmentScopeRef::of('crm.project', 4))->all())->toBe(['analyst'])
        ->and($a->scope()->tenant->key())->toBe('crm.organization:1')
        ->and($anna->getAttributes())->toBe($attributes);
});

it('leaves a wrapper usable and unchanged after an exception in the middle of a call', function (): void {
    $anna = User::query()->findOrFail(1);
    $a = $anna->guard('crm')->inTenant(Organization::query()->findOrFail(1));
    $panel = app(CurrentPanel::class)->get();

    expect(fn () => $a->hasPermission('backoffice:clients.view'))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $a->hasPermission('clients.view', guard: 'backoffice'))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $a->grantRole('edtor'))->toThrow(UnknownRoleException::class)
        ->and($a->panel()->id())->toBe('crm')
        ->and($a->scope()->tenant->key())->toBe('crm.organization:1')
        ->and($a->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(1)))->toBeTrue()
        ->and(app(CurrentPanel::class)->get())->toBe($panel)
        ->and(app(CurrentContext::class)->get($a->panel()))->toBeNull();
});

it('rejects a conflicting guard even for an empty permission batch', function (string $method): void {
    $access = User::query()->findOrFail(1)->guard('crm');

    expect($access->{$method}([], guard: 'crm'))->toBeFalse()
        ->and(fn () => $access->{$method}([], guard: 'backoffice'))->toThrow(ConflictingPanelException::class);
})->with(['hasAnyPermission', 'hasAllPermissions']);

it('captures no ambient tenant: the next request, job or fiber answers with its own current scope', function (): void {
    $anna = User::query()->findOrFail(1);
    $crm = $anna->guard('crm');
    $panel = $crm->panel();
    $p1 = AssignmentScopeRef::of('crm.project', 1);
    $a = CrmWorld::scope(1);

    $inside = app(WithinContext::class)->run($panel, $a, static fn (): array => [$crm->roleNames(on: $p1)->all(), $crm->scope()->tenant->key()]);

    expect($inside)->toBe([['seller'], 'crm.organization:1'])
        ->and(fn () => $crm->roleNames(on: $p1))->toThrow(TenantRequiredException::class);

    // Another request on the same worker: scoped state is fresh, the wrapper of the previous request reads it.
    app(CurrentContext::class)->set($panel, $a);
    app()->forgetScopedInstances();
    ChangeWorld::panel();

    expect(fn () => $crm->roleNames(on: $p1))->toThrow(TenantRequiredException::class);

    // Two fibers with different current tenants share one wrapper without seeing each other's tenant.
    $answers = [];
    $current = app(CurrentContext::class);
    $first = new Fiber(static function () use ($current, $panel, $crm, $p1, &$answers): void {
        $current->set($panel, CrmWorld::scope(1));
        Fiber::suspend();
        $answers['A'] = $crm->roleNames(on: $p1)->all();
    });
    $second = new Fiber(static function () use ($current, $panel, $crm, &$answers): void {
        $current->set($panel, CrmWorld::scope(2));
        $answers['B'] = $crm->roleNames(on: AssignmentScopeRef::of('crm.project', 4))->all();
    });
    $first->start();
    $second->start();
    $first->resume();

    expect($answers)->toBe(['B' => ['analyst'], 'A' => ['seller']])
        ->and($current->get($panel))->toBeNull();
});
