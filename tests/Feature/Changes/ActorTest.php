<?php

declare(strict_types=1);

use AzGuard\Changes\ActingActor;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\FixedGuard;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\SellerProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    CrmWorld::seed();
    FixedGuard::$user = null;
    Auth::extend('crm-fixed', static fn (): FixedGuard => new FixedGuard);
    config(['auth.guards.web' => ['driver' => 'crm-fixed']]);
    Auth::forgetGuards();
    $this->seen = [];
    $seen = &$this->seen;
    $this->panel = W::panel([static function (Change $change, Closure $next) use (&$seen): ChangeResult {
        $seen[] = $change->context();

        return $next($change);
    }]);
});
afterEach(function (): void {
    FixedGuard::$user = null;
    CrmWorld::resetRuntime();
});

function storedActor(): array
{
    $row = W::rows()[array_key_last(W::rows())];

    return [$row['actor_type'], $row['actor_id'], $row['actor_reason']];
}

it('takes the actor from the panel subject guard and never replaces the target', function (): void {
    FixedGuard::$user = User::query()->findOrFail(2);
    W::grant($this->panel, 'seller', 1, 5, fields: ['region' => 'R1']);
    $context = $this->seen[0];

    expect(storedActor())->toBe(['crm.user', '2', null])
        ->and($context->actor)->toEqual(ActorRef::of('crm.user', 2))
        ->and($context->actorModel?->getKey())->toBe(2)
        ->and($context->user?->getKey())->toBe(1)
        ->and(end(SellerProjects::$observed)->user?->getKey())->toBe(1)
        ->and(end(SellerProjects::$observed)->actor)->toEqual(ActorRef::of('crm.user', 2))
        ->and(end(SellerProjects::$observed)->proposed)->toBe(['until' => null, 'fields' => ['region' => 'R1']]);
});

it('prefers the innermost explicit frame, including an explicit null actor, over the guard', function (): void {
    FixedGuard::$user = User::query()->findOrFail(2);
    $actors = app(ActingActor::class);

    $actors->run(ActorRef::of('crm.user', 3, 'ticket 7'), function () use ($actors): void {
        W::grant($this->panel, 'analyst', 2, 1);
        expect(storedActor())->toBe(['crm.user', '3', 'ticket 7']);

        expect($this->seen[0]->actorModel?->getKey())->toBe(3);
        $actors->run(null, fn () => W::grant($this->panel, 'auditor', 2, null));
        expect(storedActor())->toBe([null, null, null])
            ->and($this->seen[1]->actor)->toBeNull()->and($this->seen[1]->actorModel)->toBeNull()
            ->and($actors->current($this->panel))->toEqual(ActorRef::of('crm.user', 3, 'ticket 7'));
    });

    expect($actors->current($this->panel))->toEqual(ActorRef::of('crm.user', 2));
});

it('falls back to the console system actor and keeps an explicit argument first', function (): void {
    W::grant($this->panel, 'analyst', 2, 1);
    expect(storedActor())->toBe([ActorRef::SYSTEM_TYPE, null, 'console']);

    W::grant($this->panel, 'auditor', 2, null, actor: ActorRef::of('crm.user', 1));
    expect(storedActor())->toBe(['crm.user', '1', null]);
});

it('refuses explicitly when an Assignment filter requires an actor that is null', function (): void {
    $panel = W::panel(configure: fn (PanelBuilder $p) => $p->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()
        ->filter(static fn (Builder $query, ActorRef $actor) => $query->where('id', '>', 0)))));
    $before = W::rows();

    app(ActingActor::class)->run(null, fn () => expect(fn () => W::grant($panel, 'analyst', 2, 1))
        ->toThrow(AssignmentScopeNotAcceptedException::class, 'refused by its filters'));
    expect(W::rows())->toBe($before)
        ->and(W::grant($panel, 'analyst', 2, 1, actor: ActorRef::of('crm.user', 3))->applied())->toBeTrue();
});

it('isolates explicit actor frames between fibers', function (): void {
    $actors = app(ActingActor::class);
    $fiber = new Fiber(fn () => $actors->run(ActorRef::of('crm.user', 9), function () use ($actors): ?ActorRef {
        Fiber::suspend($actors->current($this->panel));

        return $actors->current($this->panel);
    }));
    $inside = $fiber->start();

    expect($inside)->toEqual(ActorRef::of('crm.user', 9))
        ->and($actors->current($this->panel))->toEqual(ActorRef::system('console'));
    $fiber->resume();
    expect($fiber->getReturn())->toEqual(ActorRef::of('crm.user', 9));
});
