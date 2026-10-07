<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Changes\GrantDetails;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantRecord;
use AzGuard\Changes\PanelManagers;
use AzGuard\Changes\RoleKeyMigration;
use AzGuard\Contracts\Changes\GrantManager;
use AzGuard\Contracts\Roles\RoleCatalog;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Schema\PermissionSchema;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Feature\Changes\Managers\ManagerWorld as M;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;

/** The decision for Anna on client 1 (project P1, region R1) in a fresh request scope. */
function managersDecide(Panel $panel, int $client = 1, int $user = 1, Action $action = Action::View): Decision
{
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);

    return World::decide($panel, $client, $action, $user);
}

/** The stored seller grant of Anna on P1 in tenant A, as the editor of tenant A reads it. */
function managersAnnaSeller(GrantManager $grants): GrantRecord
{
    return $grants->page(new GrantFilter(subject: W::user(1), context: W::project(1), role: W::role('seller')))->items[0]
        ?? throw new RuntimeException('Anna has no seller grant on P1.');
}

it('R15 validates edited grant fields again and decides with the new values', function (): void {
    $panel = W::panel();
    $grants = PanelManagers::for($panel, W::tenant())->grants();
    $seller = managersAnnaSeller($grants);

    World::assertDecision(managersDecide($panel), true, DecisionReason::Granted);

    $grants->update($seller->id, new GrantDetails(null, ['region' => 'R2']), $seller->fingerprint);
    World::assertDecision(managersDecide($panel), false, DecisionReason::NotGranted);

    $edited = $grants->find($seller->id);
    $grants->update($seller->id, new GrantDetails(null, ['region' => 'R1']), $edited?->fingerprint);
    World::assertDecision(managersDecide($panel), true, DecisionReason::Granted);

    // Anna moved to Samara: the seller filter of P1 (Kazan) refuses the same edit; the stored row stays as it was.
    User::query()->whereKey(1)->update(['city_id' => 2]);
    $row = M::row($seller->id);

    expect(fn () => $grants->update($seller->id, new GrantDetails(null, ['region' => 'R1', 'eligible' => true])))
        ->toThrow(AssignmentScopeNotAcceptedException::class)
        ->and(M::row($seller->id))->toBe($row);
});

it('R25 offers no definition mutation: a raw request for a role or a filter changes nothing, a valid assignment passes', function (): void {
    $panel = W::panel();
    $managers = PanelManagers::for($panel, W::tenant());
    $grants = $managers->grants();
    $seller = managersAnnaSeller($grants);
    $state = [W::rows(), W::version(), app(PanelRegistry::class)->catalog('crm')->roles()];
    $methods = array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(RoleCatalog::class))->getMethods());

    expect($methods)->toBe(['all', 'find'])
        ->and($managers->roles()->find('seller')?->editable)->toBeFalse()
        ->and(fn () => $grants->update($seller->id, new GrantDetails(null, ['role' => 'tenant-admin'])))->toThrow(InvalidChangeFieldsException::class)
        ->and(fn () => $grants->update($seller->id, new GrantDetails(null, ['filter' => 'App\\Filters\\Everything'])))->toThrow(InvalidChangeFieldsException::class)
        ->and([W::rows(), W::version(), app(PanelRegistry::class)->catalog('crm')->roles()])->toBe($state)
        ->and($grants->update($seller->id, new GrantDetails(null, ['region' => 'R1']), $seller->fingerprint)->applied())->toBeTrue();
});

it('R37 R28 keeps the editor of tenant A out of tenant B: ids, forms and cursors of B are refused', function (): void {
    $panel = W::panel();
    $a = PanelManagers::for($panel, W::tenant())->grants();
    $b = PanelManagers::for($panel, W::tenant(2))->grants();
    W::grant($panel, 'seller', 1, 4, 2);
    $analystB = $b->page(new GrantFilter(subject: W::user(1), role: W::role('analyst')))->items[0];
    $cursorB = $b->page(new GrantFilter(limit: 1))->nextCursor;
    $state = [W::rows(), W::version()];

    expect($analystB->scope->context->key())->toBe('crm.project:4')
        ->and($a->find($analystB->id))->toBeNull()
        ->and(fn () => $a->update($analystB->id, new GrantDetails(null, ['region' => 'R1']), $analystB->fingerprint))->toThrow(StaleSelectionException::class)
        ->and(fn () => $a->revokeMany([managersAnnaSeller($a)->id, $analystB->id]))->toThrow(StaleSelectionException::class)
        ->and($cursorB)->toBeString()
        ->and(fn () => $a->page(new GrantFilter(limit: 1, cursor: $cursorB)))->toThrow(InvalidArgumentException::class)
        ->and([W::rows(), W::version()])->toBe($state)
        // R28: the admin read API lists stored grants with their stored scope, including those the code no longer qualifies.
        ->and(array_map(fn (GrantRecord $record): string => $record->scope->tenant->key(), $a->page(new GrantFilter(limit: 500))->items))
        ->each->toBe('crm.organization:1');
});

it('R42 revokes one manual grant while the relation membership and the external origin keep allowing', function (): void {
    Project::query()->findOrFail(1)->members()->attach(1, ['role' => 'seller']);
    $panel = W::panel(sources: [World::database(), RelationSource::make(ProjectScope::make(), 'members', 'pivot.role')]);
    $imported = W::grant($panel, 'seller', 1, 1, origin: 'import')->record?->id ?? '';
    $manual = PanelManagers::for($panel, W::tenant())->grants();
    $import = PanelManagers::for($panel, W::tenant(), 'import')->grants();
    $tenantB = W::rows();
    $tenantB = array_values(array_filter($tenantB, fn (array $row): bool => $row['tenant_key'] === 'crm.organization:2'));

    expect($manual->find($imported))->toBeNull();

    $manual->revokeMany([managersAnnaSeller($manual)->id]);

    expect($import->find($imported))->not->toBeNull()
        ->and(array_values(array_filter(W::rows(), fn (array $row): bool => $row['tenant_key'] === 'crm.organization:2')))->toBe($tenantB)
        ->and($manual->page(new GrantFilter(subject: W::user(1), context: W::project(1)))->items)->toBe([]);
    World::assertDecision(managersDecide($panel), true, DecisionReason::Granted);

    $import->revokeMany([$imported]);
    World::assertDecision(managersDecide($panel), true, DecisionReason::Granted);

    Project::query()->findOrFail(1)->members()->detach(1);
    World::assertDecision(managersDecide($panel), false, DecisionReason::NotGranted);
});

it('R65 lists enum permissions without a stored copy and creates a Grants-only action only after the opt-in', function (): void {
    $plain = PanelManagers::for(W::panel(), W::tenant())->permissions();
    $listed = array_combine(array_map(fn (PermissionSchema $permission): string => $permission->key->local(), $plain->all()), $plain->all());

    expect($listed['clients.view']->dynamic)->toBeFalse()
        ->and($listed['clients.view_own_profile']->authority)->toBe(PermissionAuthority::Policy)
        ->and(fn () => $plain->create('campaigns.launch'))->toThrow(PanelNotWritableException::class)
        ->and(W::actions())->toBe([]);
    World::assertDecision(managersDecide(W::panel()), true, DecisionReason::Granted);

    $dynamic = PanelManagers::for(W::dynamicPanel(), W::tenant())->permissions();
    $dynamic->create('campaigns.launch', 'Запуск');
    $action = array_values(array_filter($dynamic->all(), fn (PermissionSchema $permission): bool => $permission->dynamic));

    expect($action)->toHaveCount(1)
        ->and($action[0]->authority)->toBe(PermissionAuthority::Grants)
        ->and(W::actionNames())->toBe(['crm.organization:1|campaigns.launch']);
});

it('R66 R29 keeps a renamed role without authority until the explicit migration and cleans removed roles and modes', function (): void {
    $panel = W::panel();
    $former = M::stored('role', 'inspector', 2);
    $removed = M::stored('role', 'ghost', 2);
    $policy = M::stored('permission', 'clients.view_own_profile', 2, 'crm.project:2');
    $grants = PanelManagers::for($panel, W::tenant())->grants();
    $orphaned = fn (): array => array_map(fn (GrantRecord $record): string => $record->id, $grants->page(new GrantFilter(state: GrantFilter::ORPHANED))->items);

    World::assertDecision(managersDecide($panel, 6, 2), false, DecisionReason::NotGranted);
    expect($orphaned())->toEqualCanonicalizing([$former, $removed, $policy])
        ->and(fn () => W::grant($panel, 'inspector', 2, null))->toThrow(UnknownRoleException::class);

    app(RoleKeyMigration::class)->run($panel, 'inspector', 'auditor', W::tenant());
    World::assertDecision(managersDecide($panel, 6, 2), true, DecisionReason::Granted);

    // The stored exact grant of a permission now decided by its policy does not override the mode: the policy alone
    // decides, for Boris's own client 6 and against client 3 of P2, where the stored grant names him.
    World::assertDecision(managersDecide($panel, 6, 2, Action::ViewOwnProfile), true, DecisionReason::Policy);
    expect(managersDecide($panel, 3, 2, Action::ViewOwnProfile)->allowed())->toBeFalse();
    expect($grants->find($former)?->role?->key())->toBe('auditor')
        ->and($grants->revokeMany([$removed, $policy])->removedGrantIds())->toBe([$removed, $policy])
        ->and($orphaned())->toBe([]);
});
