<?php

declare(strict_types=1);

use AzGuard\Concerns\SubjectAccess;
use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\WithinContext;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Concerns\CrmCustomGuardUser;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User as World;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;

/*
 * R03–R05 on the public subject API: `$user->guard('crm')`, `$user->azguard()->guard('crm')` and the trait.
 * Panels crm and backoffice share the web auth guard and ClientPermission; A=1/B=2, P1–P5, C1–C6.
 */

beforeEach(fn () => ChangeWorld::panel(configure: static fn (PanelBuilder $panel) => $panel->for([World::class, CrmCustomGuardUser::class], guard: 'web')));

it('R03 selects the crm panel without switching Auth, and keeps native mass assignment for arrays', function (): void {
    $anna = World::query()->findOrFail(1);
    $session = new GenericUser(['id' => 1]);
    Auth::guard('web')->setUser($session);
    $driver = Auth::getDefaultDriver();
    $guarded = $anna->getGuarded();

    $selected = $anna->guard('crm');
    $panels = $anna->azguard()->guard('crm');

    expect($selected)->toBeInstanceOf(SubjectAccess::class)->and($panels)->not->toBe($selected)
        ->and([$selected->panel()->id(), $panels->panel()->id()])->toBe(['crm', 'crm'])
        ->and($selected->inTenant(Organization::query()->findOrFail(1))->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(1)))->toBeTrue()
        ->and(Auth::getDefaultDriver())->toBe($driver)
        ->and(Auth::guard('web')->user())->toBe($session)
        ->and($anna->getGuarded())->toBe($guarded)
        ->and($anna->guard(guarded: ['secret']))->toBe($anna)
        ->and($anna->getGuarded())->toBe(['secret'])
        ->and($anna->mergeGuarded(['is_root'])->getGuarded())->toBe(['secret', 'is_root'])
        ->and($anna->fill(['name' => 'Анна К.', 'secret' => 's', 'is_root' => true])->isDirty(['secret', 'is_root']))->toBeFalse()
        ->and($anna->isDirty('name'))->toBeTrue()
        ->and(Auth::guard('web')->user())->toBe($session);
});

it('R03 keeps the own guard() of a consumer model and reaches the panel through azguard()', function (): void {
    $consumer = CrmCustomGuardUser::query()->findOrFail(1);

    expect($consumer->guard(['secret']))->toBe($consumer)
        ->and($consumer->guardCalls)->toBe([['secret']])
        ->and($consumer->azguard()->guard('crm')->inTenant(Organization::query()->findOrFail(1))->hasPermission(ClientPermission::Update, on: Client::query()->findOrFail(1)))->toBeTrue()
        ->and($consumer->azguard()->panels())->toBe(['crm'])
        ->and($consumer->guardCalls)->toBe([['secret']]);
});

it('R04 keeps independent wrappers of one user instance through nested calls and exceptions', function (): void {
    $anna = World::query()->findOrFail(1);
    $crm = $anna->guard('crm');
    $a = $crm->inTenant(Organization::query()->findOrFail(1));
    $b = $crm->inTenant(Organization::query()->findOrFail(2));
    $panel = $crm->panel();
    $hint = app(PanelRegistry::class)->get('backoffice');
    app(CurrentPanel::class)->set($hint);
    $c1 = Client::query()->findOrFail(1);
    $c5 = Client::query()->findOrFail(5);

    $nested = app(WithinContext::class)->run($panel, CrmWorld::scope(2), static function () use ($a, $b, $c1, $c5): array {
        try {
            $a->hasPermission('backoffice:clients.view', on: $c1);
        } catch (ConflictingPanelException) {
        }

        return [$a->hasPermission(ClientPermission::Update, on: $c1), $b->hasPermission(ClientPermission::Update, on: $c5), $b->hasPermission(ClientPermission::View, on: $c5)];
    });

    expect($nested)->toBe([true, false, true])
        ->and($a->scope()->tenant->key())->toBe('crm.organization:1')
        ->and($b->scope()->tenant->key())->toBe('crm.organization:2')
        ->and($a->roleNames(on: AssignmentScopeRef::of('crm.project', 1))->all())->toBe(['seller'])
        ->and(app(CurrentPanel::class)->get())->toBe($hint)
        ->and(app(CurrentContext::class)->get($panel))->toBeNull()
        ->and($anna->guard('crm'))->not->toBe($crm);
});

it('R05 refuses a full name of backoffice inside the explicitly selected crm and a shared enum without a panel', function (): void {
    $anna = World::query()->findOrFail(1);
    $c1 = Client::query()->findOrFail(1);
    $a = $anna->guard('crm')->inTenant(Organization::query()->findOrFail(1));
    app(CurrentPanel::class)->set(app(PanelRegistry::class)->get('backoffice'));

    expect(fn () => $a->hasPermission('backoffice:clients.view', on: $c1))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $a->hasPermission('backoffice.clients.view', on: $c1))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $a->hasPermission(ClientPermission::View, on: $c1, guard: 'backoffice'))->toThrow(ConflictingPanelException::class)
        ->and(fn () => $anna->hasPermission(ClientPermission::View, on: $c1))->toThrow(AmbiguousPanelException::class)
        ->and($a->hasPermission(ClientPermission::View, on: $c1))->toBeTrue()
        ->and($a->hasPermission('crm.clients.view', on: $c1))->toBeTrue()
        ->and($a->hasPermission('crm:clients.view', on: $c1))->toBeTrue()
        ->and($anna->inTenant(Organization::query()->findOrFail(1))->panel()->id())->toBe('backoffice')
        ->and($anna->hasPermission(ClientPermission::View, on: $c1, guard: 'crm'))->toBeTrue()
        ->and($anna->hasPermission('backoffice:clients.view', on: $c1))->toBeFalse()
        ->and($anna->hasPermission('clients.view', on: $c1))->toBeFalse();
});

it('R05 keeps an enum with guard unchanged when the crm prefix is its id, custom or off', function (string|false|null $prefix): void {
    ChangeWorld::panel(configure: static function (PanelBuilder $panel) use ($prefix): void {
        if ($prefix !== null) {
            $panel->resourcePrefix($prefix);
        }
    });
    $anna = World::query()->findOrFail(1);
    $a = $anna->guard('crm')->inTenant(Organization::query()->findOrFail(1));
    $c1 = Client::query()->findOrFail(1);
    $c3 = Client::query()->findOrFail(3);
    $names = $a->permissionNames(on: AssignmentScopeRef::of('crm.project', 1))->all();
    $expected = match ($prefix) {
        null => ['crm.clients.update', 'crm.clients.view', 'crm.clients.view_any'],
        'sales' => ['sales.clients.update', 'sales.clients.view', 'sales.clients.view_any'],
        false => ['clients.update', 'clients.view', 'clients.view_any'],
    };

    expect([$a->hasPermission(ClientPermission::Update, on: $c1), $a->hasPermission(ClientPermission::Update, on: $c3)])->toBe([true, false])
        ->and($a->hasPermission('crm:clients.update', on: $c1))->toBeTrue()
        ->and($names)->toBe($expected)
        ->and($a->hasAllPermissions($names, on: $c1))->toBeTrue();
})->with(['id' => [null], 'custom' => ['sales'], 'off' => [false]]);
