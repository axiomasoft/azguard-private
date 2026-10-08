<?php

declare(strict_types=1);

use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Guards\AdminMemberRole;
use AzGuard\Tests\Fixtures\Filament\Guards\ArchivedOrderPolicy;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderRulesPolicy;
use AzGuard\Tests\Fixtures\Filament\Guards\PolicyArchivedOrderPermission;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\ArchivedOrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListArchivedOrders;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use AzGuard\Tests\Fixtures\Filament\Widgets\OrderCountWidget;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * V95, Filament part: the list, its count and pages, global search, the records of a relation manager and a counting
 * widget see only the records the user may view, filtered in the query before any LIMIT, OFFSET or COUNT. A policy-only
 * resource is filtered by its policy without reading a grant.
 */

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
});

it('V95 lists and counts only the visible records', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords(Order::query()->findMany([1, 2, 4]))
        ->assertCanNotSeeTableRecords(Order::query()->findMany([3]))
        ->assertCountTableRecords(3);
});

it('V95 filters before LIMIT, OFFSET and COUNT', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    expect(OrderResource::getEloquentQuery()->orderBy('id')->limit(2)->pluck('id')->all())->toBe([1, 2])
        ->and(OrderResource::getEloquentQuery()->orderBy('id')->offset(2)->limit(2)->pluck('id')->all())->toBe([4])
        ->and(OrderResource::getEloquentQuery()->count())->toBe(3);
});

it('V95 shows nothing without the view permission, and the list stays closed without view_any', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any']));

    expect(OrderResource::getEloquentQuery()->count())->toBe(0);
    Livewire::test(ListOrders::class)->assertCountTableRecords(0);

    GateWorld::revoke('orders.view_any');
    GateWorld::grant(['orders.view']);
    $this->actingAs(User::query()->findOrFail(1))->get('/admin/orders')->assertForbidden();
});

it('V95 finds in global search only the visible records', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    expect(OrderResource::getGlobalSearchResults('S-3')->count())->toBe(0)
        ->and(OrderResource::getGlobalSearchResults('A-')->count())->toBe(2);
});

it('V95 counts in a widget only the visible records', function (): void {
    GateWorld::serve(GateWorld::grant(['orders.view', 'widgets.order-count']));

    Livewire::test(OrderCountWidget::class)->assertSee('Count: 3');
});

it('V95 shows in a relation manager only the related records the user may view', function (): void {
    GateWorld::serve(GateWorld::grant(['products.view_any', 'products.view']));

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => Order::query()->findOrFail(1), 'pageClass' => ListOrders::class])
        ->assertCountTableRecords(1)
        ->assertCanNotSeeTableRecords(Order::query()->findOrFail(1)->products()->whereKey(2)->get());
});

it('V95 decides a policy-only resource by its policy without reading a grant', function (): void {
    FilamentFixture::$guardPermissions = [OrderPermission::class, PolicyArchivedOrderPermission::class];
    FilamentFixture::$guardPolicies = [OrderRulesPolicy::class, ArchivedOrderPolicy::class];
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(User::query()->findOrFail(1));
    $grantReads = 0;
    DB::listen(function (QueryExecuted $query) use (&$grantReads): void {
        $grantReads += preg_match('/azg_(role|permission)_grants/', $query->sql);
    });

    Livewire::test(ListArchivedOrders::class)
        ->assertCanSeeTableRecords(Order::query()->findMany([1, 2, 4]))
        ->assertCountTableRecords(3);

    expect(ArchivedOrderResource::canViewAny())->toBeTrue()
        ->and(ArchivedOrderResource::getEloquentQuery()->pluck('id')->sort()->values()->all())->toBe([1, 2, 4])
        ->and($grantReads)->toBe(0);

    // Control: the resource decided by grants reads them.
    OrderResource::getEloquentQuery()->count();
    expect($grantReads)->toBeGreaterThan(0);
});

it('refuses a query that the guard panel cannot filter exactly, as a configuration error', function (): void {
    // A role granted automatically gives no exact query filter.
    FilamentFixture::$adminRoles = [AdminMemberRole::class];
    $this->bootFilament();
    GateWorld::serve(User::query()->findOrFail(1));

    expect(fn () => OrderResource::getEloquentQuery()->count())->toThrow(VisibilityNotSupportedException::class);
});
