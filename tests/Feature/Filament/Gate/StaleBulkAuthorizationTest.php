<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Guards\EntryPermission;
use AzGuard\Tests\Fixtures\Filament\Guards\OrderPermission;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use Filament\Actions\BulkAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.delete_any', 'orders.delete']));
    FilamentFixture::$toolbarActions = [BulkAction::make('delete')->action(static function (ListOrders $livewire): void {
        Order::query()->whereKey($livewire->selectedTableRecords)->delete();
    })];
});

it('refuses a custom bulk callback when a forged or missing key is absent from the visible selection', function (string $key): void {
    $page = Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', $key]);
    $page->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])->assertForbidden();

    expect(Order::query()->whereKey(1)->exists())->toBeTrue()
        ->and(Order::query()->whereKey(3)->exists())->toBeTrue();
})->with(['hidden record' => '3', 'missing record' => '999']);

it('rechecks each selected record after its state changes before a custom callback', function (): void {
    $page = Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '2']);
    Order::query()->whereKey(2)->update(['locked' => true]);
    $page->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])->assertForbidden();

    expect(Order::query()->whereKey([1, 2])->count())->toBe(2);
});

it('does not authorize a stale model whose current primary state denies the action', function (): void {
    $stale = Order::query()->findOrFail(1);
    Order::query()->whereKey(1)->update(['locked' => true]);

    expect(FilamentGate::resource(OrderResource::class, 'delete', $stale)->denied())->toBeTrue();
});

it('refuses a substituted model even when its key matches an authorized order', function (): void {
    expect(FilamentGate::resource(OrderResource::class, 'delete', Product::query()->findOrFail(1))->denied())->toBeTrue();
});

it('refuses a selected action after the role granting it is removed', function (): void {
    FilamentFixture::$adminRoles = [DeletingMemberForGate::class];
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    $user = GateWorld::grant(['orders.view_any', 'orders.view']);
    GateWorld::serve($user);
    FilamentFixture::$toolbarActions = [BulkAction::make('delete')->action(static function (ListOrders $livewire): void {
        Order::query()->whereKey($livewire->selectedTableRecords)->delete();
    })];
    $page = Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '2']);
    AzGuard::panel('admin')->for($user)->revokeRole('member');

    $page->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true]);

    expect(Order::query()->whereKey([1, 2])->count())->toBe(2);
});

it('refuses a previously resolved record after it is soft deleted', function (): void {
    Schema::table('orders', static fn (Blueprint $table) => $table->softDeletes());
    GateWorld::grant(['orders.update', 'orders.restore']);
    $stale = SoftDeletedOrderForGate::query()->findOrFail(1);

    expect(FilamentGate::resource(SoftOrderResourceForGate::class, 'update', $stale)->allowed())->toBeTrue();

    SoftDeletedOrderForGate::query()->findOrFail(1)->delete();

    expect(FilamentGate::resource(SoftOrderResourceForGate::class, 'update', $stale)->denied())->toBeTrue()
        ->and(FilamentGate::resource(SoftOrderResourceForGate::class, 'restore', $stale)->allowed())->toBeTrue();
});

it('refuses a selected bulk action after the actor loses its permission', function (): void {
    $page = Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '2']);
    GateWorld::revoke('orders.delete_any');
    GateWorld::revoke('orders.delete');

    $page->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true]);

    expect(Order::query()->whereKey([1, 2])->count())->toBe(2);
});

it('allows a custom bulk callback when the complete selection remains authorized', function (): void {
    Livewire::test(ListOrders::class)->set('selectedTableRecords', ['1', '2'])
        ->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])->assertSuccessful();

    expect(Order::query()->whereKey([1, 2])->count())->toBe(0);
});

final class SoftDeletedOrderForGate extends Model
{
    use SoftDeletes;

    protected $table = 'orders';

    public $timestamps = false;
}

#[Role('member')]
final class DeletingMemberForGate extends BaseRole
{
    public function permissions(): array
    {
        return [EntryPermission::Enter, OrderPermission::DeleteAny, OrderPermission::Delete];
    }
}

final class SoftOrderResourceForGate extends OrderResource
{
    protected static ?string $model = SoftDeletedOrderForGate::class;

    protected static ?string $slug = 'orders';
}
