<?php

declare(strict_types=1);

use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Testing\FakeSubject;
use AzGuard\Testing\InteractsWithAzGuard;
use AzGuard\Tests\Fixtures\Testing\KitOrder;
use AzGuard\Tests\Fixtures\Testing\KitStore;
use AzGuard\Tests\Fixtures\Testing\KitWorld;
use AzGuard\Tests\Fixtures\Testing\OrdersPermission;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The kit signs a subject in with real grants stored through the change pipeline; the checks of the test run through
 * the same engine as in production. The database is not wrapped in a transaction: the engine reads authority only
 * outside a transaction it did not open.
 */

uses(DatabaseMigrations::class, InteractsWithAzGuard::class);

beforeEach(function (): void {
    KitWorld::panel();
    Route::bind('order', static fn (string $id): KitOrder => KitOrder::make((int) $id, store: 5));
    Route::post('/orders/{order}/cancel', static fn (): string => 'cancelled')->middleware(['web', 'azguard.can:seller:orders.cancel,order'])->name('seller.orders.cancel');
});
afterEach(function (): void {
    KitWorld::reset();
});

it('lets the signed-in subject through a route that checks the granted permission (12 §7)', function (): void {
    $fake = AzGuard::fake();
    $store = KitStore::make(5);
    $user = FakeSubject::of(1);
    $this->actingAsWithPermissions($user, ['seller:orders.cancel'], on: $store);
    $this->post(route('seller.orders.cancel', ['order' => 42]))->assertOk();
    $fake->assertChecked('seller:orders.cancel');
    $fake->assertDecided($user, 'seller:orders.cancel', Effect::Allow);
});

it('grants through the change pipeline as the system actor testing and signs in on the guard of the panel', function (): void {
    $fake = AzGuard::fake();
    $user = FakeSubject::of(2);

    expect($this->actingAsWithPermissions($user, [OrdersPermission::View, 'seller:orders.cancel']))->toBe($this)
        ->and(Auth::guard('web')->user())->toBe($user);
    $fake->assertPermissionGranted($user, OrdersPermission::View);
    $fake->assertPermissionGranted($user, 'seller:orders.cancel');
    expect($fake->changes())->toHaveCount(2)
        ->and($fake->changes()[0]->actor?->type)->toBe('azguard.system')
        ->and($fake->changes()[0]->actor?->reason)->toBe('testing');
});

it('grants in the given scope and nowhere else', function (): void {
    $fake = AzGuard::fake();
    $user = FakeSubject::of(3);
    $store = KitStore::make(5);
    $this->actingAsWithPermissions($user, ['seller:orders.cancel'], on: $store);

    $fake->assertPermissionGranted($user, 'seller:orders.cancel', on: $store);
    $fake->assertPermissionGranted($user, 'seller:orders.cancel', on: AssignmentScopeRef::of('store', 5));
    $fake->assertPermissionGranted($user, 'seller:orders.cancel', on: AnyAssignmentScope::all());
    expect(fn () => $fake->assertPermissionGranted($user, 'seller:orders.cancel'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertPermissionGranted($user, 'seller:orders.cancel', on: KitStore::make(6)))->toThrow(AssertionFailedError::class)
        ->and(AzGuard::check($user, 'seller:orders.cancel', $store))->toBeTrue()
        ->and(AzGuard::check($user, 'seller:orders.cancel', KitStore::make(6)))->toBeFalse();
});

it('does not allow what was not granted: the fake decides nothing and has no allow-all mode', function (): void {
    $fake = AzGuard::fake();
    $stranger = FakeSubject::of(9);
    $user = FakeSubject::of(1);
    $this->actingAsWithPermissions($user, [OrdersPermission::View]);

    $this->actingAs($stranger, 'web')->post(route('seller.orders.cancel', ['order' => 1]))->assertForbidden();
    $this->actingAs($user, 'web')->post(route('seller.orders.cancel', ['order' => 1]))->assertForbidden();

    $fake->assertDecided($stranger, 'seller:orders.cancel', Effect::Deny);
    $fake->assertDecided($user, 'seller:orders.cancel', Effect::Deny);
    expect(fn () => $fake->assertDecided($user, 'seller:orders.cancel', Effect::Allow))->toThrow(AssertionFailedError::class)
        ->and(AzGuard::panel('seller')->for($user)->decide('seller:orders.cancel')->reason)->toBe(DecisionReason::NotGranted);
});

it('signs in a superadmin through the superadmin role of the panel', function (): void {
    $fake = AzGuard::fake();
    $admin = FakeSubject::of(4);
    $this->actingAsSuperAdmin($admin);

    $fake->assertRoleGranted($admin, 'superadmin');
    $this->post(route('seller.orders.cancel', ['order' => 1]))->assertOk();
    $fake->assertDecided($admin, 'seller:orders.cancel', Effect::Allow);
    expect(AzGuard::panel('seller')->for($admin)->isSuperAdmin())->toBeTrue();
});

it('refuses a superadmin when the panel has no superadmin role', function (): void {
    KitWorld::panel(superAdmin: false);
    $admin = FakeSubject::of(4);

    expect(fn () => $this->actingAsSuperAdmin($admin))->toThrow(UnknownRoleException::class);
});

it('is not signed in before the call and survives the application events being faked', function (): void {
    expect(Auth::guard('web')->user())->toBeNull();
    Event::fake();
    $fake = AzGuard::fake();
    $user = FakeSubject::of(1);
    $this->actingAsWithPermissions($user, ['seller:orders.cancel']);
    $this->post(route('seller.orders.cancel', ['order' => 1]))->assertOk();

    $fake->assertPermissionGranted($user, 'seller:orders.cancel');
    $fake->assertChecked('seller:orders.cancel');
    Event::assertNotDispatched(AzGuard\Events\AccessDecided::class);
});

it('keeps the check a deny with source_error when the test wraps the database in a transaction', function (): void {
    $this->app->make('db')->connection()->beginTransaction();

    try {
        $user = FakeSubject::of(1);
        $this->actingAsWithPermissions($user, ['seller:orders.cancel']);
        $decision = AzGuard::panel('seller')->for($user)->decide('seller:orders.cancel');

        expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::SourceError);
    } finally {
        $this->app->make('db')->connection()->rollBack();
    }
});
