<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeType;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantRecord;
use AzGuard\Contracts\Changes\GrantManager;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Feature\Changes\Managers\ManagerWorld as M;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use Illuminate\Support\Carbon;

/** @return list<string> */
function orphanIds(GrantManager $grants, string $state): array
{
    return array_map(fn (GrantRecord $record): string => $record->id, $grants->page(new GrantFilter(state: $state, limit: 500))->items);
}

beforeEach(function (): void {
    CrmWorld::seed();
    $this->panel = W::panel();
    // Rows an older build, an import or a mode switch left behind; the current code gives none of them authority.
    $this->orphans = [
        'removed role' => M::stored('role', 'ghost', 2),
        'former key' => M::stored('role', 'inspector', 2),
        'not grantable' => M::stored('role', 'root', 2),
        'removed scope type' => M::stored('role', 'auditor', 2, 'crm.region:1'),
        'scope type the role is not bound to' => M::stored('role', 'auditor', 2, 'crm.project:1'),
        'no scope for a scoped role' => M::stored('role', 'seller', 2),
        'removed exact permission' => M::stored('permission', 'clients.archive', 2),
        'exact permission now decided by policy' => M::stored('permission', 'clients.view_own_profile', 2),
        'pattern covering nothing' => M::stored('permission', 'reports.*', 2),
        'permission in a removed scope type' => M::stored('permission', 'clients.view', 2, 'crm.region:1'),
    ];
    $this->known = [
        'pattern covering a grants permission' => M::stored('permission', 'clients.*', 2, 'crm.project:2'),
        'exact grants permission' => M::stored('permission', 'clients.view', 2, 'crm.project:2'),
        'role in its scope' => W::grant($this->panel, 'auditor', 1, null)->record?->id,
    ];
    $this->grants = M::managers($this->panel)->grants();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
});

it('V113 R29 lists removed roles, scopes and PolicyOnly exact permissions as orphaned and gives them no authority', function (): void {
    $orphaned = orphanIds($this->grants, GrantFilter::ORPHANED);
    $active = orphanIds($this->grants, GrantFilter::ACTIVE);

    expect($orphaned)->toEqualCanonicalizing(array_values($this->orphans))
        ->and(array_intersect($active, $orphaned))->toBe([])
        ->and($active)->toContain(...array_values($this->known))
        ->and(orphanIds($this->grants, GrantFilter::ANY))->toEqualCanonicalizing([...$orphaned, ...$active])
        ->and($this->grants->find($this->orphans['removed role'])?->role?->key())->toBe('ghost')
        ->and($this->grants->find($this->orphans['removed scope type'])?->scope->context->key())->toBe('crm.region:1');

    // Client 6 lies in project 5: only a global or a project 5 grant could open it to Boris.
    CrmWorld::assertDecision(CrmWorld::decide($this->panel, 6, ClientPermission::View, user: 2), false, DecisionReason::NotGranted);
    CrmWorld::assertDecision(CrmWorld::decide($this->panel, 6, ClientPermission::ViewOwnProfile, user: 2), true, DecisionReason::Policy);
});

it('V113 R29 revokes orphaned grants by their stored scope without live eligibility', function (): void {
    $seen = [];
    $panel = W::panel([function (Change $change, Closure $next) use (&$seen) {
        $seen[] = [$change->type, $change->context()->phase->value, $change->context()->role];

        return $next($change);
    }]);
    EventWorld::listen();
    $result = M::managers($panel)->grants()->revokeMany(array_values($this->orphans));

    expect($result->removedGrantIds())->toBe(array_values($this->orphans))
        ->and(array_unique(array_map(fn (array $entry): string => $entry[1], $seen)))->toBe(['revocation'])
        ->and(array_map(fn (array $entry): ChangeType => $entry[0], $seen))->toBe([...array_fill(0, 6, ChangeType::RevokeRole), ...array_fill(0, 4, ChangeType::RevokePermission)])
        ->and($seen[0][2])->toBeNull()
        ->and(EventWorld::types())->toBe([...array_fill(0, 6, 'role.revoked'), ...array_fill(0, 4, 'permission.revoked')])
        ->and(orphanIds($this->grants, GrantFilter::ORPHANED))->toBe([])
        ->and(orphanIds($this->grants, GrantFilter::ACTIVE))->toContain(...array_values($this->known));
});

it('V91 shares one code catalog between tenants A and B while their grants and cleanups stay apart', function (): void {
    $ghostB = M::stored('role', 'ghost', 1, tenant: 2);
    $a = M::managers($this->panel);
    $b = M::managers($this->panel, 2);

    expect($b->roles()->all())->toEqual($a->roles()->all())
        ->and(orphanIds($b->grants(), GrantFilter::ORPHANED))->toBe([$ghostB])
        ->and(orphanIds($b->grants(), GrantFilter::ACTIVE))->toHaveCount(1)
        ->and(fn () => $a->grants()->revokeMany([$ghostB]))->toThrow(StaleSelectionException::class);

    $a->grants()->revokeMany([$this->orphans['removed role']]);

    expect(M::row($ghostB))->not->toBeNull()
        ->and($b->grants()->revokeMany([$ghostB])->removedGrantIds())->toBe([$ghostB])
        ->and(M::row($this->orphans['former key']))->not->toBeNull();
});

it('treats every permission grant of a roles-only writer as orphaned', function (): void {
    $panel = W::panel(sources: [CrmWorld::database()->rolesOnly()]);
    $grants = M::managers($panel)->grants();
    $permissions = array_values(array_filter(orphanIds($grants, GrantFilter::ANY), fn (string $id): bool => str_starts_with($id, 'permission:')));

    expect($permissions)->toHaveCount(6)
        ->and(array_values(array_intersect(orphanIds($grants, GrantFilter::ORPHANED), $permissions)))->toBe($permissions)
        ->and($grants->revokeMany([$this->known['exact grants permission']])->removedGrantIds())->toBe([$this->known['exact grants permission']]);
});

it('follows Access on a panel that requires a scope: tenant-wide grants stay known unless the role requires a scope', function (): void {
    $panel = W::panel(configure: static fn (PanelBuilder $builder) => $builder->scopes(
        AssignmentScopePolicy::required(ProjectScope::make()
            ->filter(new ActiveProjects))));
    CrmWorld::clear(1);
    $role = M::stored('role', 'auditor', 3);
    $permission = M::stored('permission', 'clients.view', 3);
    $grants = M::managers($panel)->grants();

    expect($panel->scopes()->mode())->toBe('required')
        ->and(orphanIds($grants, GrantFilter::ACTIVE))->toContain($role, $permission)
        ->and(orphanIds($grants, GrantFilter::ORPHANED))->not->toContain($role, $permission)
        ->and(orphanIds($grants, GrantFilter::ORPHANED))->toContain($this->orphans['no scope for a scoped role']);
    CrmWorld::assertDecision(CrmWorld::decide($panel, 6, ClientPermission::View, user: 3), true, DecisionReason::Granted);

    M::managers($panel)->grants()->revokeMany([$role]);
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);
    CrmWorld::assertDecision(CrmWorld::decide($panel, 6, ClientPermission::View, user: 3), true, DecisionReason::Granted);

    M::managers($panel)->grants()->revokeMany([$permission]);
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);
    CrmWorld::assertDecision(CrmWorld::decide($panel, 6, ClientPermission::View, user: 3), false, DecisionReason::NotGranted);
});
