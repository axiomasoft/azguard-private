<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\Reads;
use AzGuard\Storage\AuthorityReadBaseline;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Testing\FakeSubject;
use AzGuard\Testing\InteractsWithAzGuard;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Testing\KitClerkRole;
use AzGuard\Tests\Fixtures\Testing\KitOrder;
use AzGuard\Tests\Fixtures\Testing\KitStore;
use AzGuard\Tests\Fixtures\Testing\KitWorld;
use AzGuard\Tests\Fixtures\Testing\OrdersPermission;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The kit signs a subject in with real grants stored through the change pipeline; the checks of the test run through
 * the same engine as in production, including the cache and fence inside Laravel's isolated baseline transaction.
 */

uses(RefreshDatabase::class, InteractsWithAzGuard::class);

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

it('signs in with roles: a role, unlike a direct permission, admits to an azguard.panel route', function (): void {
    KitWorld::panel(static fn (PanelBuilder $panel) => $panel->roles([KitClerkRole::class]));
    Route::post('/panel/orders/{order}/cancel', static fn (): string => 'cancelled')
        ->middleware(['web', 'azguard.panel:seller', 'azguard.can:orders.cancel,order']);
    $fake = AzGuard::fake();
    $direct = FakeSubject::of(5);
    $clerk = FakeSubject::of(6);

    $this->actingAsWithPermissions($direct, [OrdersPermission::Cancel]);
    $this->post('/panel/orders/1/cancel')->assertForbidden();

    expect($this->actingAsWithRoles($clerk, [KitClerkRole::class]))->toBe($this)
        ->and(Auth::guard('web')->user())->toBe($clerk);
    $this->post('/panel/orders/1/cancel')->assertOk();
    $fake->assertRoleGranted($clerk, 'clerk');
    expect($fake->changes()[1]->actor?->reason)->toBe('testing');
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

it('keeps the check a deny with source_error inside another transaction above the test baseline', function (): void {
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

it('executes the shared cache and invalidates grants on a nested change commit inside RefreshDatabase', function (): void {
    KitWorld::panel(static fn (PanelBuilder $panel) => $panel->cache('array', ttl: 3600));
    $user = FakeSubject::of(1);
    $this->actingAsWithPermissions($user, [OrdersPermission::View]);
    expect(AzGuard::check($user, OrdersPermission::View))->toBeTrue();
    app()->forgetScopedInstances();
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);

    expect(AzGuard::check($user, OrdersPermission::View))->toBeTrue()
        ->and($budget['grants'])->toBe(0)->and($budget['state'])->toBeGreaterThan(0);

    AzGuard::actingAs('testing', static fn () => AzGuard::panel('seller')->for($user)->revokePermission(OrdersPermission::View));
    expect(AzGuard::panel('seller')->for($user)->decide(OrdersPermission::View)->reason)->toBe(DecisionReason::NotGranted);
});

it('binds read sessions and cache identities to the exact test root across rollback and restart', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $old = $storage->readSession(Reads::Primary);
    $baseline = app(AuthorityReadBaseline::class);
    $identity = $old->authorityIdentity();
    $this->actingAsWithPermissions(FakeSubject::of(1), [OrdersPermission::View]);
    $storage->connection()->rollBack();

    expect(fn () => $old->assertUsable())->toThrow(InvalidConfigurationException::class)
        ->and($baseline->identity($storage))->toBeNull()
        ->and($storage->table('permission_grants')->count())->toBe(0);

    $this->beginDatabaseTransaction();
    expect(fn () => $storage->readSession(Reads::Primary))->toThrow(InvalidConfigurationException::class);
    $this->setUpInteractsWithAzGuard();
    expect($storage->readSession(Reads::Primary)->authorityIdentity())->not->toBe($identity)
        ->and(fn () => $old->assertUsable())->toThrow(InvalidConfigurationException::class)
        ->and(AzGuard::panel('seller')->for(FakeSubject::of(1))->decide(OrdersPermission::View)->reason)->toBe(DecisionReason::NotGranted);
});

it('publishes change callbacks only when the transaction above the test baseline commits', function (bool $commit): void {
    $fake = AzGuard::fake();
    $user = FakeSubject::of(1);
    $storage = app(StorageRegistry::class)->get('default');
    $storage->connection()->beginTransaction();
    $this->actingAsWithPermissions($user, [OrdersPermission::View]);
    expect($fake->changes())->toBe([]);
    $commit ? $storage->connection()->commit() : $storage->connection()->rollBack();

    expect($fake->changes())->toHaveCount($commit ? 1 : 0)
        ->and(AzGuard::check($user, OrdersPermission::View))->toBe($commit)
        ->and($storage->state('seller')?->version)->toBe($commit ? 1 : null);
})->with([true, false]);

it('does not reuse a rolled-back shared cache entry when the next test root reaches the same revision', function (): void {
    KitWorld::panel(static fn (PanelBuilder $panel) => $panel->cache('array', ttl: 3600));
    $storage = app(StorageRegistry::class)->get('default');
    $connection = $storage->connection();
    $connection->rollBack();
    $storage->mutate('seller', static fn (): null => null);
    $this->beginDatabaseTransaction();
    $this->setUpInteractsWithAzGuard();

    try {
        $user = FakeSubject::of(1);
        $this->actingAsWithPermissions($user, [OrdersPermission::View]);
        expect(AzGuard::check($user, OrdersPermission::View))->toBeTrue();
        $first = $storage->state('seller');
        $connection->rollBack();
        $this->beginDatabaseTransaction();
        $this->setUpInteractsWithAzGuard();
        $this->actingAsWithPermissions(FakeSubject::of(2), [OrdersPermission::View]);
        app()->forgetScopedInstances();
        $second = $storage->state('seller');

        expect($second->incarnation)->toBe($first->incarnation)
            ->and($second->version)->toBe($first->version)
            ->and(AzGuard::panel('seller')->for($user)->decide(OrdersPermission::View)->reason)->toBe(DecisionReason::NotGranted);
    } finally {
        $connection->rollBack();
        $storage->table('panel_state')->where('panel', 'seller')->delete();
        $this->beginDatabaseTransaction();
    }
});

it('rejects the test baseline when its environment manager execution context or PDO changes', function (string $change): void {
    $storage = app(StorageRegistry::class)->get('default');
    $session = $storage->readSession(Reads::Default);
    $manager = app('db.transactions');
    $pdo = $storage->connection()->getRawPdo();

    try {
        if ($change === 'environment') {
            app()->detectEnvironment(static fn (): string => 'production');
        } elseif ($change === 'manager') {
            app()->instance('db.transactions', new DatabaseTransactionsManager);
        } elseif ($change === 'pdo') {
            $storage->connection()->setPdo(new PDO('sqlite::memory:'));
        }
        $assert = static fn () => $session->assertUsable();
        expect($change === 'fiber' ? static fn () => (new Fiber($assert))->start() : $assert)
            ->toThrow(InvalidConfigurationException::class);
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
        app()->instance('db.transactions', $manager);

        if ($change === 'pdo') {
            $pdo->rollBack();
            $storage->connection()->setPdo($pdo);
            $this->beginDatabaseTransaction();
        }
    }
})->with(['environment', 'manager', 'fiber', 'pdo']);
