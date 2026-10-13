<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Authorization\TokenAbilitiesRestriction;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use AzGuard\Tests\Modules\Scopes\Fixtures\ScopedResource;
use AzGuard\Tests\Modules\Scopes\Fixtures\ScopeSource;
use Illuminate\Support\Carbon;

uses()->group('properties');

it('P2 allows only after tenant resource membership mandatory restrictions and token caps pass', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $admin = $s->flag();
        $member = $s->flag();
        $restrictionPasses = $s->flag();
        $tokenPasses = $s->flag();
        $source = new ScopeSource(direct: $admin ? [] : [$s->grant($s->scope())], roles: $admin ? [$s->role($s->scope(), key: 'tenant-admin')] : []);
        [$engine, $panel, $request] = $s->world([$source], function (PanelBuilder $p) use ($s, $member, $restrictionPasses, $tokenPasses): void {
            $p->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership($member ? [$s->subject->id()] : [])))
                ->restrictions([new RecordingRestriction(deny: ! $restrictionPasses), new TokenAbilitiesRestriction($tokenPasses ? ['orders.view'] : [])]);
        }, mode: 'inherit');
        $decision = $engine->decide($panel, $request->inScope($s->scope(true), new ScopedResource($s->scope(true))));
        $this->assertSame($member && $restrictionPasses && $tokenPasses, $decision->allowed(), "P2 seed=$seed");

        // Immutable owner boundaries deny even with a valid admin and permissive restrictions.
        foreach ([AccessScope::in($s->foreignTenant, $s->context), AccessScope::in($s->tenant, $s->otherContext)] as $owner) {
            $decision = $engine->decide($panel, $request->inScope($s->scope(true), new ScopedResource($owner)));
            $this->assertFalse($decision->allowed(), "P2 owner seed=$seed");
            expect($decision->reason)->toBe($owner->tenant->equals($s->tenant) ? DecisionReason::AssignmentScopeMismatch : DecisionReason::TenantMismatch);
        }
        expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::TenantRequired);

        [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->restrictions([new TokenAbilitiesRestriction(['orders.view'])]), mode: 'inherit');
        expect($engine->decide($panel, $request->inScope($s->scope(true), new ScopedResource($s->scope(true))))->allowed())->toBeTrue();
    }
});

it('P4 implements the scope table and isolated C does not depend on C2 grants', function (): void {
    // This truth table is the normative oracle, independent of ScopeEligibility/compiler.
    $cells = [
        'inherit' => [true, true, true],
        'isolated' => [true, false, true],
        'required' => [false, true, true],
        'none' => [true, false, false],
    ];
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        foreach ($cells as $mode => [$wideWithoutContext, $wideInContext, $localInContext]) {
            foreach ([false, true] as $role) {
                foreach ([false, true] as $local) {
                    $scope = $s->scope($local);
                    $source = new ScopeSource(direct: $role ? [] : [$s->grant($scope)], roles: $role ? [$s->role($scope, key: 'reader')] : []);
                    [$engine, $panel, $request] = $s->world([$source], mode: $mode);
                    $this->assertSame(! $local && $wideWithoutContext, $engine->decide($panel, $request->inScope($s->scope()))->allowed(), "P4 wide $mode role=$role local=$local seed=$seed");
                    $selected = $request->inScope($s->scope(true));
                    $before = $engine->decide($panel, $selected)->allowed();
                    $this->assertSame($local ? $localInContext : $wideInContext, $before, "P4 context $mode role=$role local=$local seed=$seed");
                    $otherScope = AccessScope::in($s->tenant, $s->otherContext);

                    if ($role) {
                        $source->roles[] = $s->role($otherScope, key: 'reader');
                    } else {
                        $source->direct[] = $s->grant($otherScope);
                    }
                    $this->assertSame($before, $engine->decide($panel, $selected)->allowed(), "P4 C2 $mode seed=$seed");
                }
            }
            // An unregistered context is rejected before any assignment read.
            $source = new ScopeSource(direct: [$s->grant($s->scope())]);
            [$engine, $panel, $request] = $s->world([$source], mode: $mode);
            $unknown = AccessScope::in($s->tenant, AssignmentScopeRef::of('unknown', $s->context->id()));
            expect($engine->decide($panel, $request->inScope($unknown))->reason)->toBe(DecisionReason::AssignmentScopeNotAccepted);
            expect($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);
        }
    }
});
