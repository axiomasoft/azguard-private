<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Scopes\Internal\ScopeEvaluator;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\PoliciesGate\GatePermission;
use AzGuard\Tests\Fixtures\PoliciesGate\GateRecord;
use AzGuard\Tests\Fixtures\PoliciesGate\GateWorld;
use AzGuard\Tests\Fixtures\PoliciesGate\PhpPolicy;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

final class GateSourceContractTest extends TestCase
{
    use SourceContractTests;

    protected function azguardSource(): Source
    {
        return GateSource::make();
    }

    public function test_gate_scalar_and_batch_agree_and_visibility_refuses_an_unqueryable_policy(): void
    {
        GateWorld::seed();

        try {
            Gate::define('contract-gate', static fn (User $user, GateRecord $record): bool => $record->getKey() === 1);
            [$authorizer, $panel] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'contract-gate')]);
            $allow = new GateRecord;
            $allow->setAttribute('id', 1);
            $deny = new GateRecord;
            $deny->setAttribute('id', 2);
            $requests = [GateWorld::request()->on(null, $allow), GateWorld::request()->on(null, $deny)];
            $batch = $authorizer->decideMany($requests);
            $first = $authorizer->decide($panel, $requests[0]);
            $this->assertTrue($first->allowed(), $first->reason->value);
            $this->assertFalse($authorizer->decide($panel, $requests[1])->allowed());
            $this->assertTrue($batch->get(0)->allowed());
            $this->assertFalse($batch->get(1)->allowed());
            $this->expectException(VisibilityNotSupportedException::class);
            app(Visibility::class)->visibleTo($panel, GateRecord::query(), $requests[0]->subject(), GatePermission::Access);
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function test_tenant_membership_still_gates_native_policy_decisions(): void
    {
        $this->exerciseTenantContext(AssignmentScopeMode::Inherit);
    }

    public function test_gate_tenant_context_inherit_mode(): void
    {
        $this->exerciseTenantContext(AssignmentScopeMode::Inherit);
    }

    public function test_gate_tenant_context_isolated_mode(): void
    {
        $this->exerciseTenantContext(AssignmentScopeMode::Isolated);
    }

    private function exerciseTenantContext(AssignmentScopeMode $mode): void
    {
        VisibilityWorld::seed();
        VisibilityWorld::$tenanted = true;

        try {
            Gate::define('contract-tenant-gate', static fn (User $user): bool => $user->getKey() === 1);
            User::query()->insert(['id' => 2]);
            $membership = new Membership(members: ['1', '2']);
            [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel) => $panel
                ->for(User::class)->resourcePrefix(false)->permissions([GateWorld::permissions(), GateSource::make()->map(GatePermission::Access, 'contract-tenant-gate')])
                ->policies([PolicyBinding::for(GatePermission::Php, PhpPolicy::class)])
                ->assignmentScopes(new AssignmentScopeDeclaration($mode, [new VisibilityProjectScope], evaluator: ScopeEvaluator::class))
                ->tenants(TenantPolicy::required(Organization::class)->requireMembership($membership))]);
            app()->instance(PanelRegistry::class, $registry);
            app()->forgetInstance(Authorizer::class);
            $panel = $registry->get('admin');
            $authorizer = app(Authorizer::class);
            $tenant = TenantRef::of('org', 'A');
            $requests = [GateWorld::request()->inTenant($tenant), AccessRequest::for(SubjectRef::of('user', 2), PermissionKey::of('admin', GatePermission::Access->value))->inTenant($tenant)];
            $batch = $authorizer->decideMany($requests);
            $first = $authorizer->decide($panel, $requests[0]);
            $this->assertTrue($first->allowed(), $first->reason->value);
            $this->assertFalse($authorizer->decide($panel, $requests[1])->allowed());
            $this->assertTrue($batch->get(0)->allowed());
            $this->assertFalse($batch->get(1)->allowed());
            $scoped = $requests[0]->inScope(AccessScope::in($tenant, AssignmentScopeRef::of('project', 1)));
            $this->assertTrue($authorizer->decide($panel, $scoped)->allowed());
            $this->assertTrue($authorizer->decideMany([$scoped])->get(0)->allowed());
            $membership->members = [];
            $this->assertFalse($authorizer->decide($panel, $requests[0])->allowed());
            $this->assertFalse($authorizer->decideMany([$requests[0]])->get(0)->allowed());
            $this->assertFalse($authorizer->decide($panel, $scoped)->allowed());
            $this->assertFalse($authorizer->decideMany([$scoped])->get(0)->allowed());
        } finally {
            VisibilityWorld::reset();
        }
    }
}
