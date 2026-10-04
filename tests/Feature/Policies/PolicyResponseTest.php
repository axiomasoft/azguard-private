<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderGrantSource;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderWorld;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\OrderPolicy;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\WorkingHoursPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(fn () => OrderWorld::seed());
afterEach(fn () => Relation::morphMap([], false));
it('preserves scalar denial details from both policy modes', function (OrderPermission $permission): void {
    OrderWorld::compile([new OrderGrantSource([OrderWorld::grant()])]);
    $response = Response::deny('Closed', 'orders.closed')->withStatus(404);
    OrderPolicy::$result = $response;
    WorkingHoursPolicy::$result = $response;
    $decision = OrderWorld::decide(OrderWorld::request($permission));
    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::Policy)
        ->and($decision->message)->toBe('Closed')->and($decision->status)->toBe(404)->and($decision->code)->toBe('orders.closed');
})->with([OrderPermission::Refund, OrderPermission::UserOnly]);
it('preserves policy-only allow details without altering authority', function (): void {
    OrderWorld::compile();
    OrderPolicy::$result = Response::allow('Available', 17)->withStatus(202);
    $decision = OrderWorld::decide(OrderWorld::request(OrderPermission::UserOnly));
    expect($decision->allowed())->toBeTrue()->and($decision->reason)->toBe(DecisionReason::Policy)
        ->and($decision->message)->toBe('Available')->and($decision->status)->toBe(202)->and($decision->code)->toBe(17);
});
it('does not let an allow response create grants authority', function (): void {
    OrderWorld::compile();
    WorkingHoursPolicy::$result = Response::allow();
    expect(OrderWorld::decide(OrderWorld::request())->reason)->toBe(DecisionReason::NotGranted);
});
it('fails closed on nonscalar Response codes and invalid policy returns', function (mixed $result): void {
    OrderWorld::compile();
    OrderPolicy::$result = $result;
    expect(OrderWorld::decide(OrderWorld::request(OrderPermission::UserOnly))->reason)->toBe(DecisionReason::PolicyError);
})->with([fn () => Response::deny('Invalid', ['array']), fn () => Response::allow('Invalid', new stdClass), fn () => 42, fn () => new stdClass]);
it('fails closed on a thrown policy exception', function (): void {
    OrderWorld::compile();
    OrderPolicy::$callback = fn () => throw new RuntimeException('policy failed');
    expect(OrderWorld::decide(OrderWorld::request(OrderPermission::UserOnly))->reason)->toBe(DecisionReason::PolicyError);
});
