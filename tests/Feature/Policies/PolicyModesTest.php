<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Guards\Orders\Order;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderGrantSource;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderWorld;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\OrderPolicy;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\WorkingHoursPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(fn () => OrderWorld::seed());
afterEach(fn () => Relation::morphMap([], false));
it('interprets each policy outcome against grants authority', function (mixed $result, bool $granted, DecisionReason $reason): void {
    $source = new OrderGrantSource($granted ? [OrderWorld::grant()] : []);
    OrderWorld::compile([$source]);
    WorkingHoursPolicy::$result = $result;
    $decision = OrderWorld::decide(OrderWorld::request());
    expect($decision->reason)->toBe($reason)->and($decision->allowed())->toBe($granted && $result !== false);
})->with([
    'grant and true' => [true, true, DecisionReason::Granted], 'grant and abstain' => [null, true, DecisionReason::Granted],
    'grant and veto' => [false, true, DecisionReason::Policy], 'true without grant' => [true, false, DecisionReason::NotGranted],
    'abstain without grant' => [null, false, DecisionReason::NotGranted], 'veto without grant' => [false, false, DecisionReason::NotGranted],
]);
it('uses a policy-only owner without reading any assignment source', function (): void {
    $source = new OrderGrantSource;
    OrderWorld::compile([$source]);
    $allowed = OrderWorld::decide(OrderWorld::request(OrderPermission::ViewOwn, resource: Order::query()->findOrFail(1)));
    $denied = OrderWorld::decide(OrderWorld::request(OrderPermission::ViewOwn, resource: Order::query()->findOrFail(2)));
    expect($allowed->allowed())->toBeTrue()->and($allowed->reason)->toBe(DecisionReason::Policy)->and($allowed->grants)->toBe([])
        ->and($denied->reason)->toBe(DecisionReason::Policy)->and($denied->allowed())->toBeFalse()
        ->and($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);
});
it('denies policy-only false and abstain and allows true', function (mixed $result, bool $allowed): void {
    OrderWorld::compile();
    OrderPolicy::$result = $result;
    $decision = OrderWorld::decide(OrderWorld::request(OrderPermission::UserOnly));
    expect($decision->allowed())->toBe($allowed)->and($decision->reason)->toBe(DecisionReason::Policy);
})->with([[true, true], [false, false], [null, false]]);
it('treats absent optional grants policy as pass', function (): void {
    $source = new OrderGrantSource([OrderWorld::grant(OrderPermission::Unbound)]);
    OrderWorld::compile([$source]);
    expect(OrderWorld::decide(OrderWorld::request(OrderPermission::Unbound))->allowed())->toBeTrue();
});
it('applies a policy veto even to qualified superadmin', function (): void {
    $root = RoleContribution::of(RoleKey::of('orders', 'root'), AccessScope::in(TenantRef::global()), 'orders-grants');
    OrderWorld::compile([new OrderGrantSource(roles: [$root])]);
    WorkingHoursPolicy::$result = false;
    $decision = OrderWorld::decide(OrderWorld::request());
    expect($decision->reason)->toBe(DecisionReason::Policy)->and($decision->allowed())->toBeFalse();
});
it('does not turn a native before allow into a grant', function (): void {
    OrderWorld::compile();
    WorkingHoursPolicy::$beforeResult = true;
    WorkingHoursPolicy::$result = false;
    $decision = OrderWorld::decide(OrderWorld::request());
    expect($decision->reason)->toBe(DecisionReason::NotGranted)->and(WorkingHoursPolicy::$calls)->toBe(0);
});

it('uses native before as the policy outcome while retaining grants authority', function (mixed $before, bool $allowed): void {
    OrderWorld::compile([new OrderGrantSource([OrderWorld::grant()])]);
    WorkingHoursPolicy::$beforeResult = $before;
    WorkingHoursPolicy::$result = ! $allowed;
    $decision = OrderWorld::decide(OrderWorld::request());
    expect($decision->allowed())->toBe($allowed)->and(WorkingHoursPolicy::$calls)->toBe(0);
})->with([[true, true], [false, false]]);
