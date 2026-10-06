<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Authorization\TokenAbilitiesRestriction;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\ScopedResource;
use AzGuard\Tests\Fixtures\Scopes\ScopeSource;
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

it('P6 typed subjects tenants and contexts with overlapping ids remain distinct in decisions', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $grant = $s->grant($s->scope(true), source: 'identity-'.$seed);
        $source = new CacheSource(name: 'identity-'.$seed, read: function (SubjectRef $subject, array $scopes, EvaluationContext $context) use ($s, $grant): iterable {
            if ($subject->equals($s->subject) && array_any($scopes, fn (AccessScope $scope): bool => $scope->equals($grant->scope))) {
                yield $grant;
            }
        });
        [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->cache('array'), mode: 'isolated');
        $this->assertTrue($engine->decide($panel, $request->inScope($s->scope(true)))->allowed(), "P6 control seed=$seed");
        $this->assertTrue($engine->decide($panel, $request->inScope($s->scope(true)))->allowed(), "P6 warmed seed=$seed");
        expect($source->grantReads)->toBe(1);
        $otherSubject = SubjectRef::of('user', $s->subject->id().'-other');
        $this->assertFalse($engine->decide($panel, AccessRequest::for($otherSubject, $request->permission())->inScope($s->scope(true)))->allowed(), "P6 subject seed=$seed");
        foreach ([AccessScope::in($s->foreignTenant), AccessScope::in($s->tenant, $s->otherContext), $s->scope()] as $scope) {
            $this->assertFalse($engine->decide($panel, $request->inScope($scope))->allowed(), "P6 scope seed=$seed");
        }
        $this->assertTrue($engine->decide($panel, $request->inScope($s->scope(true)))->allowed(), "P6 no cache pollution seed=$seed");
        // Kind tags, aliases, leading zeroes and delimiter-bearing ids cannot collapse.
        $references = [$s->subject, $otherSubject, $s->tenant, $s->foreignTenant, $s->context, $s->otherContext,
            SubjectRef::of('user', '01'), SubjectRef::of('user', 1), SubjectRef::of('external-user', $s->subject->id()), TenantRef::global()];
        $keys = array_map(fn (object $ref): string => json_encode(IdentityCodec::encode($ref), JSON_THROW_ON_ERROR), $references);
        // The generated subject may itself be user:01; compare distinct value encodings only.
        $uniqueReferences = array_unique(array_map(fn (object $ref): string => $ref::class.':'.$ref->key(), $references));
        expect(array_unique($keys))->toHaveCount(count($uniqueReferences));
    }
});

it('P13 foreign tenant roles and global ordinary grants never authorize a selected tenant', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        foreach (['inherit', 'isolated', 'required', 'none'] as $mode) {
            $selected = $s->scope($mode !== 'none');
            $foreign = AccessScope::in($s->foreignTenant);
            $source = new ScopeSource(direct: [$s->grant($s->scope(global: true)), $s->grant($foreign)], roles: [$s->role($foreign, key: 'reader')]);
            [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->tenants(TenantPolicy::required(Organization::class)
                ->requireMembership(new Membership([$s->subject->id()]))->allowGlobalRoles([GrantableRootRole::class])), mode: $mode);
            $this->assertFalse($engine->decide($panel, $request->inScope($selected))->allowed(), "P13 $mode seed=$seed");
            // Positive control uses one witness in exactly the selected tenant/context.
            $source->direct[] = $s->grant($selected);
            $this->assertTrue($engine->decide($panel, $request->inScope($selected))->allowed(), "P13 control $mode seed=$seed");
        }
    }
});
