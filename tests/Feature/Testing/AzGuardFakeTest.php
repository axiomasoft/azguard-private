<?php

declare(strict_types=1);

use AzGuard\Events\AccessEvent;
use AzGuard\Events\EventObservers;
use AzGuard\Events\EventType;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Testing\AzGuardFake;
use AzGuard\Testing\FakeSubject;
use AzGuard\Testing\InteractsWithAzGuard;
use AzGuard\Tests\Fixtures\Testing\KitWorld;
use AzGuard\Tests\Fixtures\Testing\OrdersPermission;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\AssertionFailedError;

uses(DatabaseMigrations::class, InteractsWithAzGuard::class);

beforeEach(function (): void {
    KitWorld::panel();
});
afterEach(function (): void {
    KitWorld::reset();
});

it('records the checks of a panel that does not trace with the values of the real decision', function (): void {
    $fake = AzGuard::fake();
    $user = FakeSubject::of(1);
    AzGuard::check($user, OrdersPermission::View);

    expect($fake)->toBeInstanceOf(AzGuardFake::class)->and($fake->checks())->toHaveCount(1)
        ->and($fake->checks()[0]->panel)->toBe('seller')
        ->and($fake->checks()[0]->subject->key())->toBe('azguard.fake-subject:1')
        ->and($fake->checks()[0]->permission->full())->toBe('seller:orders.view')
        ->and($fake->checks()[0]->effect)->toBe(Effect::Deny)
        ->and($fake->checks()[0]->reason)->toBe(DecisionReason::NotGranted);
});

it('counts checks and denies the assertion of a check that was not made', function (): void {
    $fake = AzGuard::fake();
    $user = FakeSubject::of(1);
    AzGuard::check($user, OrdersPermission::View);
    AzGuard::check($user, OrdersPermission::View);

    $fake->assertChecked(OrdersPermission::View);
    $fake->assertChecked('seller:orders.view', times: 2);
    $fake->assertNotChecked(OrdersPermission::Cancel);
    expect(fn () => $fake->assertChecked(OrdersPermission::Cancel))->toThrow(AssertionFailedError::class, 'Recorded checks')
        ->and(fn () => $fake->assertChecked(OrdersPermission::View, times: 1))->toThrow(AssertionFailedError::class, 'checked 2')
        ->and(fn () => $fake->assertNotChecked(OrdersPermission::View))->toThrow(AssertionFailedError::class);
});

it('names the recorded checks in a failure so the test can be fixed', function (): void {
    $fake = AzGuard::fake();
    AzGuard::check(FakeSubject::of(1), OrdersPermission::View);

    expect(fn () => $fake->assertChecked(OrdersPermission::Cancel))
        ->toThrow(AssertionFailedError::class, 'azguard.fake-subject:1 seller:orders.view deny');
});

it('records grants and revokes as changes with the actor, subject and scope', function (): void {
    $fake = AzGuard::fake();
    $user = FakeSubject::of(1);
    AzGuard::actingAs('seeder', function () use ($user): void {
        AzGuard::panel('seller')->for($user)->grantRole('superadmin');
        AzGuard::panel('seller')->for($user)->grantPermission(OrdersPermission::View);
        AzGuard::panel('seller')->for($user)->revokeRole('superadmin');
        AzGuard::panel('seller')->for($user)->revokePermission(OrdersPermission::View);
    });

    $fake->assertRoleGranted($user, 'superadmin');
    $fake->assertRoleRevoked($user, 'seller:superadmin');
    $fake->assertPermissionGranted($user, OrdersPermission::View);
    $fake->assertPermissionRevoked($user, 'orders.view');
    expect(array_map(static fn ($change): EventType => $change->type, $fake->changes()))
        ->toBe([EventType::RoleGranted, EventType::PermissionGranted, EventType::RoleRevoked, EventType::PermissionRevoked])
        ->and($fake->changes()[0]->actor?->reason)->toBe('seeder')
        ->and($fake->changes()[0]->subject?->key())->toBe('azguard.fake-subject:1')
        ->and($fake->changes()[0]->role?->full())->toBe('seller:superadmin')
        ->and($fake->changes()[1]->permission?->full())->toBe('seller:orders.view');
});

it('does not take a grant for a revoke nor another subject for the subject', function (): void {
    $fake = AzGuard::fake();
    $user = FakeSubject::of(1);
    AzGuard::actingAs('seeder', fn () => AzGuard::panel('seller')->for($user)->grantPermission(OrdersPermission::View));

    expect(fn () => $fake->assertPermissionRevoked($user, OrdersPermission::View))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertPermissionGranted(FakeSubject::of(2), OrdersPermission::View))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertPermissionGranted($user, OrdersPermission::Cancel))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertRoleGranted($user, 'superadmin'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertPermissionGranted('not a subject', OrdersPermission::View))->toThrow(InvalidArgumentException::class);
});

it('records nothing before it starts and after it stops, and a new fake starts clean', function (): void {
    $user = FakeSubject::of(1);
    AzGuard::check($user, OrdersPermission::View);
    $first = AzGuard::fake();
    AzGuard::check($user, OrdersPermission::View);
    $first->stop();
    AzGuard::check($user, OrdersPermission::View);
    $second = AzGuard::fake();
    AzGuard::check($user, OrdersPermission::Cancel);
    $second->stop();

    expect($first->checks())->toHaveCount(1)->and($second->checks())->toHaveCount(1)
        ->and($second->checks()[0]->permission->full())->toBe('seller:orders.cancel');
});

it('leaves a grant and its decision as the engine made them', function (): void {
    $user = FakeSubject::of(1);
    $before = AzGuard::check($user, OrdersPermission::View);
    AzGuard::fake();
    $this->actingAsWithPermissions($user, [OrdersPermission::View]);

    expect($before)->toBeFalse()->and(AzGuard::check($user, OrdersPermission::View))->toBeTrue()
        ->and(AzGuard::check($user, OrdersPermission::Cancel))->toBeFalse();
});

it('reports an observer that fails and still gives the event to the others and the decision to the caller', function (): void {
    Exceptions::fake();
    $observers = app(EventObservers::class);
    $seen = [];
    $observers->add(static fn () => throw new RuntimeException('broken observer'));
    $observers->add(static function (AccessEvent $event) use (&$seen): void {
        $seen[] = $event->type();
    });

    expect(AzGuard::check(FakeSubject::of(1), OrdersPermission::View))->toBeFalse()->and($seen)->toBe([EventType::AccessDecided]);
    Exceptions::assertReported(RuntimeException::class);
});

it('forgets an observer and stops building events when none is left', function (): void {
    $observers = app(EventObservers::class);
    $handle = $observers->add(static fn () => null);

    expect($observers->active())->toBeTrue();
    $observers->forget($handle);
    expect($observers->active())->toBeFalse();
});
