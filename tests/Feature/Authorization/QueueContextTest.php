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
