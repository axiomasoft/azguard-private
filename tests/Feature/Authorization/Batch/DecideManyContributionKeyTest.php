<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Crm\Models\Organization as CrmOrganization;
use AzGuard\Tests\Fixtures\Scopes\ConfiguredProjectScope;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\ReaderRole;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses()->group('batch');

/** An external eligibility that depends on the contribution witness: only grants of orders.view are eligible. */
function viewOnlyAdapter(): AssignmentScopeAccessAdapter
{
    return new class implements AssignmentScopeAccessAdapter
    {
        public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
        {
            return ! $runtime->grant instanceof Grant || $runtime->grant->pattern->local() === 'orders.view';
        }

        public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
        {
            $out = [];
            foreach ($refs as $ref) {
                $out[$ref->key()] = $this->allows($ref, $runtime);
            }

            return $out;
        }

        public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
        {
            throw new RuntimeException('external');
        }
    };
}

it('A03 keeps one external verdict per contribution in either grant order', function (bool $viewFirst): void {
    $tenant = TenantRef::of('org', 'A');
    $definition = new StoreScope(fn (AssignmentScopeRef $ref): ResolvedAssignmentScope => new ResolvedAssignmentScope($ref, $tenant));
    app()->instance(StoreScope::class, $definition);
    $one = AccessScope::in($tenant, AssignmentScopeRef::of('store', 1));
    $grants = [
        Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', $one),
        Grant::of(PermissionPattern::of('admin', 'orders.edit'), 'generated', $one),
    ];
    [$engine, $panel, $request] = AuthorizationWorld::compile(
        new GeneratedSource(direct: $viewFirst ? $grants : array_reverse($grants)),
        fn (PanelBuilder $panel) => $panel->roles([ReaderRole::class])
            ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
            ->scopes(AssignmentScopePolicy::inherit($definition)->accessAdapter('store', viewOnlyAdapter())),
    );
    $view = $request->inScope($one);
    $edit = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.edit'))->inScope($one);

    $scalar = [$engine->decide($panel, $view), $engine->decide($panel, $edit)];
    $set = $engine->decideMany([$view, $edit]);

    expect($scalar[0]->allowed())->toBeTrue()->and($scalar[1]->allowed())->toBeFalse()
        ->and($set->get(0)->allowed())->toBe($scalar[0]->allowed())->and($set->get(0)->reason)->toBe($scalar[0]->reason)
        ->and($set->get(1)->allowed())->toBe($scalar[1]->allowed())->and($set->get(1)->reason)->toBe($scalar[1]->reason);
})->with([true, false]);

/** Admits only the contribution recorded on a concrete context. */
final class ConcreteContextOnlyFilter implements AssignmentScopeFilter
{
    public function apply(Builder $query, AssignmentScopeRuntime $runtime): void
    {
        if ($runtime->grant !== null && $runtime->grant->scope->context->isGlobal()) {
            $query->whereRaw('1 = 0');
        }
    }
}

#[Role('scope-filtered')]
final class ContributionScopeFilteredRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }

    public function scopes(): array
    {
        return [ConfiguredProjectScope::make()->filter(new ConcreteContextOnlyFilter)];
    }
}

it('A03 keeps the native verdict of a tenant-wide and a context-specific contribution of one role apart', function (): void {
    Schema::create('projects', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('organization_id');
        $table->boolean('is_active');
    });
    app('db')->table('projects')->insert(['id' => 1, 'organization_id' => 1, 'is_active' => true]);
    $tenant = TenantRef::of('crm.organization', 1);
    $project = AssignmentScopeRef::of('crm.project', 1);
    $role = RoleKey::of('admin', 'scope-filtered');
    $source = new GeneratedSource(roles: [
        RoleContribution::of($role, AccessScope::in($tenant), 'generated'),
        RoleContribution::of($role, AccessScope::in($tenant, $project), 'generated'),
    ]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel
        ->roles([ContributionScopeFilteredRole::class])
        ->tenants(TenantPolicy::required(CrmOrganization::class)->requireMembership(new Membership(['1'])))
        ->scopes(AssignmentScopePolicy::inherit(ConfiguredProjectScope::make())));
    $request = $request->inScope(AccessScope::in($tenant, $project));

    $scalar = $engine->decide($panel, $request);
    $batch = $engine->decideMany([$request, $request])->get(1);

    expect($scalar->allowed())->toBeTrue()->and($batch->allowed())->toBe($scalar->allowed())->and($batch->reason)->toBe($scalar->reason);
});
