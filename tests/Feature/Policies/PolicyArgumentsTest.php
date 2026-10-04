<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Tests\Fixtures\Guards\Orders\Clock;
use AzGuard\Tests\Fixtures\Guards\Orders\Order;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderWorld;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\OrderPolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(fn () => OrderWorld::seed());
afterEach(fn () => Relation::morphMap([], false));
it('preserves both identities when user and resource have the same class', function (): void {
    OrderWorld::compile();
    $resource = User::query()->findOrFail(2);
    OrderPolicy::$callback = fn (User $user, User $target): bool => $user->getKey() === 1 && $target === $resource && $user !== $target;
    expect(OrderWorld::decide(OrderWorld::request(OrderPermission::SameClass, resource: $resource))->allowed())->toBeTrue();
});
it('provides the original resource and service DI to the policy', function (): void {
    OrderWorld::compile();
    $resource = Order::query()->findOrFail(1);
    $clock = new Clock(false);
    app()->instance(Clock::class, $clock);
    OrderPolicy::$callback = fn (User $user, Order $target, Clock $injected): bool => $target === $resource && $injected === $clock;
    expect(OrderWorld::decide(OrderWorld::request(OrderPermission::ViewOwn, resource: $resource))->allowed())->toBeTrue();
});
it('does not invent a required model through the container', function (): void {
    OrderWorld::compile();
    $constructed = 0;
    app()->bind(Order::class, function () use (&$constructed): Order {
        $constructed++;

        return new Order;
    });
    $decision = OrderWorld::decide(OrderWorld::request(OrderPermission::Missing));
    expect($decision->reason)->toBe(DecisionReason::PolicyError)->and(OrderPolicy::$calls)->toBe(0)->and($constructed)->toBe(0);
});
it('supplies a resource class only to a declared compatible parameter', function (OrderPermission $permission): void {
    OrderWorld::compile();
    expect(OrderWorld::decide(OrderWorld::request($permission))->allowed())->toBeTrue()
        ->and(OrderPolicy::$arguments[1])->toBe(Order::class);
})->with([OrderPermission::ResourceClass, OrderPermission::StringClass]);
it('passes only a subject to user-only collection policies', function (OrderPermission $permission): void {
    OrderWorld::compile();
    expect(OrderWorld::decide(OrderWorld::request($permission))->allowed())->toBeTrue()->and(OrderPolicy::$arguments)->toHaveCount(1);
})->with([OrderPermission::ViewAny, OrderPermission::Create, OrderPermission::UserOnly]);
it('passes native before subject and local ability with separate service DI', function (): void {
    OrderWorld::compile();
    $clock = new Clock;
    app()->instance(Clock::class, $clock);
    OrderPolicy::$beforeResult = false;
    $decision = OrderWorld::decide(OrderWorld::request(OrderPermission::NativeBefore));
    expect($decision->reason)->toBe(DecisionReason::Policy)->and(OrderPolicy::$calls)->toBe(0)
        ->and(OrderPolicy::$beforeArguments[0]->getKey())->toBe(1)->and(OrderPolicy::$beforeArguments[1])->toBe('orders.native_before')
        ->and(OrderPolicy::$beforeArguments[2])->toBe($clock);
});
it('honors defaults nullable and empty variadic without inventing inputs', function (): void {
    OrderWorld::compile();
    $clock = app(Clock::class);
    expect(OrderWorld::decide(OrderWorld::request(OrderPermission::Defaults))->allowed())->toBeTrue()
        ->and(OrderPolicy::$arguments[1])->toBe($clock)->and(array_slice(OrderPolicy::$arguments, 2))->toBe([null, 'default', []]);
});
