<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\WithinContext;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\EligibilityCityFilter;
use AzGuard\Tests\Fixtures\Scopes\EligibilityProjectScope;
use AzGuard\Tests\Fixtures\Scopes\EligibilitySellerRole;
use AzGuard\Tests\Fixtures\Scopes\EligibilityWorld;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(function (): void {
    EligibilityWorld::tables();
    EligibilityCityFilter::$observed = [];
});
afterEach(fn () => Relation::morphMap([], false));

it('passes the actual role contribution target and separate actor to a fresh runtime', function (): void {
    $source = new GeneratedSource(roles: [EligibilityWorld::role('seller')]);
    [$engine, $panel, $request] = EligibilityWorld::compile($source);
    $request = $request->inScope(EligibilityWorld::scope());
    expect($engine->decide($panel, $request, ActorRef::of('user', 2))->allowed())->toBeTrue();
    $runtime = EligibilityCityFilter::$observed[0];
    expect($runtime->role)->toBeInstanceOf(EligibilitySellerRole::class)->and($runtime->grant)->toBe($source->roles[0])
        ->and($runtime->user?->getKey())->toBe(1)->and($runtime->actorModel?->getKey())->toBe(2)
        ->and($runtime->phase)->toBe(AssignmentScopePhase::Access)->and($runtime->scope->equals(EligibilityWorld::scope()))->toBeTrue();
    expect($engine->decide($panel, $request, ActorRef::system('queue'))->allowed())->toBeTrue();
    expect(EligibilityCityFilter::$observed[1])->not->toBe($runtime)->and(EligibilityCityFilter::$observed[1]->actorModel)->toBeNull();
});

it('provides nullable role and grant for common direct and policy-only filters without global DI bindings', function (string $authority): void {
    $captured = [];
    $definition = EligibilityProjectScope::make()->filter(function (Builder $query, AssignmentScopeRuntime $runtime) use (&$captured): void {
        $captured[] = $runtime;
        $query->where('is_active', true);
    });
    $source = new GeneratedSource(direct: $authority === 'direct' ? [EligibilityWorld::direct()] : []);
    [$engine, $panel, $request] = EligibilityWorld::compile($source, fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit($definition)));

    if ($authority === 'policy') {
        $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    }
    expect($engine->decide($panel, $request->inScope(EligibilityWorld::scope()))->allowed())->toBeTrue();
    expect($captured)->toHaveCount(1)->and($captured[0]->role)->toBeNull()->and($captured[0]->grant)->toBeNull()
        ->and($source->grantReads)->toBe($authority === 'policy' ? 0 : 1);
    expect(app()->bound(User::class))->toBeFalse()->and(app()->bound(AssignmentScopeRuntime::class))->toBeFalse();
})->with(['direct', 'policy']);

it('rejects a required missing runtime user instead of inventing an empty model', function (): void {
    $definition = EligibilityProjectScope::make()->filter(fn (Builder $query, User $user) => $query->where('city', $user->getAttribute('city')));
    [$engine, $panel, $request] = EligibilityWorld::compile(new GeneratedSource(direct: [EligibilityWorld::direct()]), fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit($definition)));
    $request = AccessRequest::for(SubjectRef::of('user', 99), $request->permission())->inScope(EligibilityWorld::scope());
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::AssignmentScopeFilterError)
        ->and(app()->bound(User::class))->toBeFalse();
});

it('isolates interleaved fiber panel and scope stacks and restores after success or exception', function (bool $throws): void {
    [$engine, $panel, $request] = EligibilityWorld::compile(new GeneratedSource(direct: [EligibilityWorld::direct()]));
    $current = app(CurrentContext::class);
    $panels = app(CurrentPanel::class);
    $within = new WithinContext($current);
    $current->set($panel, EligibilityWorld::scope(3));
    $panels->set($panel);
    $a = new Fiber(function () use ($within, $panel, $current, $panels, $engine, $request): void {
        expect($current->get($panel))->toBeNull()->and($panels->get())->toBeNull();
        $panels->run($panel, fn () => $within->run($panel, EligibilityWorld::scope(), function () use ($current, $panel, $engine, $request): void {
            Fiber::suspend('A');
            expect($current->get($panel)?->equals(EligibilityWorld::scope()))->toBeTrue()
                ->and($engine->decide($panel, $request)->allowed())->toBeTrue();
        }));
        expect($current->get($panel))->toBeNull()->and($panels->get())->toBeNull();
    });
    $b = new Fiber(function () use ($within, $panel, $current, $panels, $throws): void {
        expect($current->get($panel))->toBeNull()->and($panels->get())->toBeNull();

        try {
            $panels->run($panel, fn () => $within->run($panel, EligibilityWorld::scope(2, 'B'), function () use ($current, $panel, $throws): void {
                Fiber::suspend('B');
                expect($current->get($panel)?->equals(EligibilityWorld::scope(2, 'B')))->toBeTrue();

                if ($throws) {
                    throw new RuntimeException('fiber failed');
                }
            }));
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('fiber failed');
        }
        expect($current->get($panel))->toBeNull()->and($panels->get())->toBeNull();
    });
    expect($a->start())->toBe('A')->and($b->start())->toBe('B');
    $a->resume();
    $b->resume();
    expect($current->get($panel)?->equals(EligibilityWorld::scope(3)))->toBeTrue()->and($panels->get())->toBe($panel);
})->with([false, true]);
