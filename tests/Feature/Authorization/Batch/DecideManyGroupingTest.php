<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\BatchExternalAdapter;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\ReaderRole;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;

uses()->group('batch');

it('keeps failures isolated by subject and panel and preserves original order', function (): void {
    User::query()->insert(['id' => 2]);
    $s = new ScenarioGenerator(2);
    $bad = new GeneratedSource(read: function (SubjectRef $subject, array $scopes, EvaluationContext $context) use ($s): iterable {
        if ($subject->id() === '2') {
            throw new RuntimeException('one subject source is unavailable');
        }

        yield $s->grant();
    });
    $other = new GeneratedSource(name: 'other', direct: [$s->grant(panel: 'beta', source: 'other')]);
    [$engine] = $s->world([$bad], otherSource: $other);
    $requests = [
        AccessRequest::for(SubjectRef::of('user', 2), PermissionKey::of('alpha', 'orders.view')),
        AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('beta', 'orders.view')),
        AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('alpha', 'orders.view')),
        AccessRequest::for(SubjectRef::of('user', 2), PermissionKey::of('alpha', 'orders.policy')),
    ];
    $set = $engine->decideMany($requests, ActorRef::of('user', 1));
    expect(array_map(fn ($d) => $d->reason, iterator_to_array($set)))->toBe([
        DecisionReason::SourceError, DecisionReason::Granted, DecisionReason::Granted, DecisionReason::Policy,
    ])->and($set->states())->toHaveCount(2);
});

it('resolves external refs once and uses allowsMany with each original grant witness', function (): void {
    $tenant = TenantRef::of('org', 'A');
    $definition = new StoreScope(fn (AssignmentScopeRef $ref): ResolvedAssignmentScope => new ResolvedAssignmentScope($ref, $tenant));
    app()->instance(StoreScope::class, $definition);
    $adapter = new BatchExternalAdapter;
    $one = AccessScope::in($tenant, AssignmentScopeRef::of('store', 1));
    $two = AccessScope::in($tenant, AssignmentScopeRef::of('store', 2));
    $source = new class(roles: [RoleContribution::of(RoleKey::of('admin', 'reader'), $one, 'generated', fields: ['eligible' => false]), RoleContribution::of(RoleKey::of('admin', 'reader'), $one, 'generated', fields: ['eligible' => true]), RoleContribution::of(RoleKey::of('admin', 'reader'), $two, 'generated', fields: ['eligible' => false])]) extends GeneratedSource
    {
        public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
        {
            $keys = array_map(fn (AccessScope $scope): string => $scope->context->key(), $scopes);

            foreach ($this->roles as $role) {
                if (in_array($role->scope->context->key(), $keys, true)) {
                    yield $role;
                }
            }
        }
    };
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel
        ->roles([ReaderRole::class])->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
        ->scopes(AssignmentScopePolicy::inherit($definition)->accessAdapter('store', $adapter)));
    $requests = [$request->inScope($one), $request->inScope($two), $request->inScope($one), $request->inScope($two)];
    $scalar = array_map(fn (AccessRequest $r) => $engine->decide($panel, $r), $requests);
    $definition->resolves = 0;
    $adapter->batchOnly = true;
    $set = $engine->decideMany($requests);
    expect(array_map(fn ($d) => $d->allowed(), iterator_to_array($set)))->toBe([true, false, true, false])
        ->and($definition->resolves)->toBe(2)->and($adapter->observed)->not->toBeEmpty();

    foreach ($scalar as $i => $decision) {
        expect($set->get($i)->reason)->toBe($decision->reason);
    }
    $fields = array_map(fn ($runtime) => $runtime->grant?->fields()['eligible'] ?? null, $adapter->observed);
    expect($fields)->toContain(false, true);

    foreach ($adapter->observed as $runtime) {
        expect($runtime->subject->id())->toBe('1')->and($runtime->actor->id)->toBe('1');

        if ($runtime->grant !== null) {
            expect($runtime->role)->toBeInstanceOf(ReaderRole::class);
        }
    }
});

it('keeps tenant partitions of the same subject independent after a source failure', function (): void {
    $source = new GeneratedSource(read: function (SubjectRef $subject, array $scopes, EvaluationContext $context): iterable {
        if ($context->scope()->tenant->id() === 'A') {
            throw new RuntimeException('tenant A source is unavailable');
        }

        yield Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', $context->scope());
    });
    [$engine, , $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel
        ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership)));
    $set = $engine->decideMany([
        $request->inTenant(TenantRef::of('org', 'A')),
        $request->inTenant(TenantRef::of('org', 'B')),
        $request->inTenant(TenantRef::of('org', 'A')),
    ]);
    expect($set->get(0)->reason)->toBe(DecisionReason::SourceError)->and($set->get(1)->allowed())->toBeTrue()
        ->and($set->get(1)->scope->tenant->id())->toBe('B')->and($set->get(2)->reason)->toBe(DecisionReason::SourceError);
});
