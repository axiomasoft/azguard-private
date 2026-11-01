<?php

declare(strict_types=1);

use AzGuard\Changes\Internal\ActingActor;
use AzGuard\Context\Internal\CurrentContext;
use AzGuard\Context\Internal\WithinContext;
use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Contracts\Tenancy\TenantResolver;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Modules\Scopes\Fixtures\ScopeWorld;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

it('resolves an empty console request and fails closed when tenant or context is required', function (): void {
    app()->instance(Request::class, Request::create('/'));
    $tenantResolver = new class implements TenantResolver
    {
        public function resolve(Request $request): ?TenantRef
        {
            return $request->header('X-Tenant') ? TenantRef::of('org', $request->header('X-Tenant')) : null;
        }
    };
    $scopeResolver = new class implements AssignmentScopeResolver
    {
        public function resolve(Request $request): ?AssignmentScopeRef
        {
            return $request->header('X-Store') ? AssignmentScopeRef::of('store', $request->header('X-Store')) : null;
        }
    };
    $configure = static fn (PanelBuilder $panel) => $panel->tenantResolvers([$tenantResolver])->scopeResolvers([$scopeResolver]);
    [$engine, $panel, $request] = ScopeWorld::compile(new GeneratedSource, 'required', $configure);
    $missingTenant = $engine->decide($panel, $request);
    $missingContext = $engine->decide($panel, $request->inTenant(TenantRef::of('org', 'A')));
    expect($missingTenant->reason)->toBe(DecisionReason::TenantRequired)
        ->and($missingTenant->scope->tenant->isGlobal())->toBeTrue()
        ->and($missingContext->reason)->toBe(DecisionReason::AssignmentScopeRequired)
        ->and($missingContext->scope->context->isGlobal())->toBeTrue();

    [$engine, $panel, $request] = ScopeWorld::compile(new GeneratedSource, 'inherit', $configure, tenant: false);
    $optional = $engine->decide($panel, $request);
    expect($optional->scope->tenant->isGlobal())->toBeTrue()
        ->and($optional->scope->context->isGlobal())->toBeTrue()
        ->and($optional->reason)->not->toBe(DecisionReason::AssignmentScopeRequired);
});

it('uses explicit and within scopes ahead of request resolvers and clears job state', function (): void {
    app()->instance(Request::class, Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT' => 'B', 'HTTP_X_STORE' => '2']));
    $tenantResolver = new class implements TenantResolver
    {
        public function resolve(Request $request): ?TenantRef
        {
            return TenantRef::of('org', $request->header('X-Tenant'));
        }
    };
    $scopeResolver = new class implements AssignmentScopeResolver
    {
        public function resolve(Request $request): ?AssignmentScopeRef
        {
            return AssignmentScopeRef::of('store', $request->header('X-Store'));
        }
    };
    [$engine, $panel, $request] = ScopeWorld::compile(new GeneratedSource, 'required', static fn (PanelBuilder $p) => $p->tenantResolvers([$tenantResolver])->scopeResolvers([$scopeResolver]));
    $a = ScopeWorld::scope('A', 1);
    $b = ScopeWorld::scope('B', 2);
    app(CurrentPanel::class)->set($panel);
    app(ActingActor::class)->run(ActorRef::system('job-one'), function () use ($engine, $panel, $request, $a, $b): void {
        app(WithinContext::class)->run($panel, $a, function () use ($engine, $panel, $request, $a, $b): void {
            expect($engine->decide($panel, $request)->scope)->toEqual($a)
                ->and($engine->decide($panel, $request->inScope($b))->scope)->toEqual($b);
            app()->forgetScopedInstances();
            expect(app(CurrentPanel::class)->get())->toBeNull()
                ->and(app(CurrentContext::class)->get($panel))->toBeNull()
                ->and(app(ActingActor::class)->current($panel))->not->toEqual(ActorRef::system('job-one'));
        });
    });
});

it('evaluates decide and decideMany without a request instance in the container and isolates sequential queue jobs', function (): void {
    $tenantResolver = new class implements TenantResolver
    {
        public function resolve(Request $request): ?TenantRef
        {
            return $request->header('X-Tenant') ? TenantRef::of('org', $request->header('X-Tenant')) : null;
        }
    };
    $scopeResolver = new class implements AssignmentScopeResolver
    {
        public function resolve(Request $request): ?AssignmentScopeRef
        {
            return $request->header('X-Store') ? AssignmentScopeRef::of('store', $request->header('X-Store')) : null;
        }
    };
    $a = ScopeWorld::scope('A', 1);
    $b = ScopeWorld::scope('B', 2);
    $grantA = ScopeWorld::grant($a);
    $source = new GeneratedSource(read: static fn ($subject, array $scopes): array => array_values(array_filter(
        [$grantA],
        static fn ($grant): bool => array_any($scopes, static fn ($scope): bool => $scope->equals($grant->scope)),
    )));
    [$engine, $panel, $request] = ScopeWorld::compile(
        $source,
        'required',
        static fn (PanelBuilder $p) => $p->tenantResolvers([$tenantResolver])->scopeResolvers([$scopeResolver]),
    );

    app()->forgetInstance('request');
    app()->forgetInstance(Request::class);
    app()->offsetUnset('request');
    app()->offsetUnset(Request::class);

    expect(app()->bound('request'))->toBeFalse()
        ->and($engine->decide($panel, $request)->allowed())->toBeFalse()
        ->and($engine->decide($panel, $request)->reason)->toBe(DecisionReason::AssignmentScopeFilterError)
        ->and($engine->decide($panel, $request->inScope($a))->allowed())->toBeTrue();

    $batch = $engine->decideMany([
        $request->inScope($a),
        $request->inScope($b),
        $request,
    ]);
    expect($batch->get(0)->allowed())->toBeTrue()
        ->and($batch->get(1)->allowed())->toBeFalse()
        ->and($batch->get(1)->reason)->toBe(DecisionReason::NotGranted)
        ->and($batch->get(2)->allowed())->toBeFalse()
        ->and($batch->get(2)->reason)->toBe(DecisionReason::AssignmentScopeFilterError);

    [$resolverlessEngine, $resolverlessPanel, $resolverlessRequest] = ScopeWorld::compile($source, 'required');
    app()->forgetInstance('request');
    app()->offsetUnset('request');
    expect($resolverlessEngine->decide($resolverlessPanel, $resolverlessRequest)->reason)->toBe(DecisionReason::TenantRequired)
        ->and($resolverlessEngine->decideMany([$resolverlessRequest->inScope($a), $resolverlessRequest])->get(0)->allowed())->toBeTrue()
        ->and($resolverlessEngine->decideMany([$resolverlessRequest->inScope($a), $resolverlessRequest])->get(1)->reason)->toBe(DecisionReason::TenantRequired);

    $observed = [];
    Bus::dispatchSync(function () use ($engine, $panel, $request, $a, &$observed): void {
        app(CurrentPanel::class)->set($panel);
        app(ActingActor::class)->run(ActorRef::system('queue-job-1'), function () use ($engine, $panel, $request, $a, &$observed): void {
            app(WithinContext::class)->run($panel, $a, function () use ($engine, $panel, $request, &$observed): void {
                $decision = $engine->decide($panel, $request);
                $observed['job1'] = [
                    'allowed' => $decision->allowed(),
                    'scope' => $decision->scope,
                    'panel' => app(CurrentPanel::class)->get()?->id(),
                    'actor' => app(ActingActor::class)->current($panel),
                ];
            });
        });
        // Leave ambient state dirty at the end of job 1 to verify forgetScopedInstances() resets it before job 2.
        app(CurrentPanel::class)->set($panel);
    });

    app()->forgetScopedInstances();
    app()->forgetInstance('request');
    app()->forgetInstance(Request::class);
    app()->offsetUnset('request');
    app()->offsetUnset(Request::class);

    Bus::dispatchSync(function () use ($engine, $panel, $request, $b, &$observed): void {
        $decisionWithoutExplicitScope = $engine->decide($panel, $request);
        $batchInJob2 = $engine->decideMany([$request->inScope($b)]);
        $observed['job2'] = [
            'ambient_panel' => app(CurrentPanel::class)->get(),
            'ambient_context' => app(CurrentContext::class)->get($panel),
            'ambient_actor' => app(ActingActor::class)->current($panel),
            'unscoped_allowed' => $decisionWithoutExplicitScope->allowed(),
            'unscoped_reason' => $decisionWithoutExplicitScope->reason,
            'scoped_b_allowed' => $batchInJob2->get(0)->allowed(),
        ];
    });

    expect($observed['job1']['allowed'])->toBeTrue()
        ->and($observed['job1']['scope'])->toEqual($a)
        ->and($observed['job1']['panel'])->toBe($panel->id())
        ->and($observed['job1']['actor'])->toEqual(ActorRef::system('queue-job-1'))
        ->and($observed['job2']['ambient_panel'])->toBeNull()
        ->and($observed['job2']['ambient_context'])->toBeNull()
        ->and($observed['job2']['ambient_actor'])->not->toEqual(ActorRef::system('queue-job-1'))
        ->and($observed['job2']['unscoped_allowed'])->toBeFalse()
        ->and($observed['job2']['unscoped_reason'])->toBe(DecisionReason::AssignmentScopeFilterError)
        ->and($observed['job2']['scoped_b_allowed'])->toBeFalse();
});
