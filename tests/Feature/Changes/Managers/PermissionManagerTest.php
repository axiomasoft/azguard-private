<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Changes\ChangeStatus;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantRecord;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Changes\PermissionRecord;
use AzGuard\Events\PermissionDeleted;
use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\Panel;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Tests\Feature\Changes\Managers\ManagerWorld as M;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Support\Carbon;

function permissionDecision(Panel $panel, string $name, int $user = 2, int $project = 2): Decision
{
    return app(Authorizer::class)->decide($panel, AccessRequest::for(W::user($user), PermissionKey::of('crm', $name))
        ->inScope(CrmWorld::scope(1, $project)));
}

/** @return array<string, PermissionSchema> */
function permissionsByKey(Panel $panel, int $tenant = 1): array
{
    $all = M::managers($panel, $tenant)->permissions()->all();

    return array_combine(array_map(fn (PermissionSchema $permission): string => $permission->key->local(), $all), $all);
}

beforeEach(function (): void {
    CrmWorld::seed();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('V82 creates a dynamic permission, grants it, decides it and deletes it with its grants in one mutation', function (): void {
    $panel = W::dynamicPanel();
    $permissions = M::managers($panel)->permissions();
    $created = $permissions->create('campaigns.launch', 'Запуск', 'Кампании');
    $grant = W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.launch'), W::project(2));
    $listed = permissionsByKey($panel);

    expect($created->status)->toBe(ChangeStatus::Applied)
        ->and($created->record)->toBeInstanceOf(PermissionRecord::class)
        ->and($listed['campaigns.launch']->dynamic)->toBeTrue()->and($listed['campaigns.launch']->authority)->toBe(PermissionAuthority::Grants)
        ->and($listed['campaigns.launch']->label)->toBe('Запуск')->and($listed['clients.view']->dynamic)->toBeFalse()
        ->and(permissionsByKey($panel, 2))->not->toHaveKey('campaigns.launch')
        ->and(permissionDecision($panel, 'campaigns.launch')->allowed())->toBeTrue();

    $permissions->update('campaigns.launch', new PermissionDetails('Старт', 'Кампании', 'Запуск кампании'));
    EventWorld::listen();
    $version = W::version();
    $deleted = $permissions->delete('campaigns.launch');
    $event = array_values(array_filter(EventWorld::events(), fn ($event): bool => $event instanceof PermissionDeleted))[0] ?? null;

    expect(array_map(fn ($effect): ChangeType => $effect->type, $deleted->effects))->toBe([ChangeType::RevokePermission, ChangeType::DeletePermission])
        ->and(W::version())->toBe($version + 1)
        ->and($event?->removedGrantIds)->toBe([$grant->record?->id])
        ->and(EventWorld::types())->toBe(['permission.revoked', 'permission.deleted'])
        ->and(W::rows('permission'))->toBe([])->and(W::actions())->toBe([])
        ->and(fn () => permissionDecision($panel, 'campaigns.launch'))->toThrow(UnknownPermissionException::class);
});

it('V82 R65 refuses a dynamic name that is a permission of an enum, a panel without the opt-in, and permission grants of a roles-only writer', function (): void {
    $dynamic = M::managers(W::dynamicPanel())->permissions();

    expect(fn () => $dynamic->create('clients.view'))->toThrow(DuplicatePermissionException::class)
        ->and(fn () => $dynamic->update('clients.view', new PermissionDetails('x', null, null)))->toThrow(UnknownPermissionException::class)
        ->and(fn () => $dynamic->delete('clients.view'))->toThrow(UnknownPermissionException::class)
        ->and(fn () => M::managers(W::panel())->permissions()->create('campaigns.launch'))->toThrow(PanelNotWritableException::class)
        ->and(W::actions())->toBe([]);

    $rolesOnly = W::dynamicPanel(rolesOnly: true);
    M::managers($rolesOnly)->permissions()->create('campaigns.launch');

    expect(fn () => W::pipeline()->grant($rolesOnly, W::tenant(), W::user(2), W::permission('campaigns.launch'), W::project(2)))
        ->toThrow(PanelNotWritableException::class)
        ->and(W::rows('permission'))->toBe([]);
});

it('R65 lists enum permissions with their immutable mode and no stored copy without the opt-in', function (): void {
    $panel = W::panel();
    $listed = permissionsByKey($panel);

    expect(array_keys($listed))->toContain('clients.view', 'clients.view_own_profile')
        ->and($listed['clients.view_own_profile']->authority)->toBe(PermissionAuthority::Policy)
        ->and($listed['clients.view']->dynamic)->toBeFalse()
        ->and(W::actions())->toBe([]);
});

it('V92 keeps a pattern grant when the dynamic permission goes and lists it as orphaned once it covers nothing', function (): void {
    $panel = W::dynamicPanel();
    $managers = M::managers($panel);
    $managers->permissions()->create('campaigns.launch');
    $exact = W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.launch'), W::project(2))->record?->id;
    $pattern = W::pipeline()->grant($panel, W::tenant(), W::user(2), W::permission('campaigns.*'), W::project(2))->record?->id;
    $orphaned = fn (): array => array_map(fn (GrantRecord $record): string => $record->id,
        $managers->grants()->page(new GrantFilter(kind: 'permission', state: GrantFilter::ORPHANED))->items);

    expect($orphaned())->toBe([]);

    $managers->permissions()->delete('campaigns.launch');

    expect($managers->grants()->find($exact ?? ''))->toBeNull()
        ->and($managers->grants()->find($pattern ?? '')?->permission?->local())->toBe('campaigns.*')
        ->and($orphaned())->toBe([$pattern])
        ->and($managers->grants()->revokeMany([$pattern ?? ''])->removedGrantIds())->toBe([$pattern]);
});
