<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use AzGuard\Tests\Fixtures\Filament\Resources\OrderResource;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use AzGuard\Tests\TestCase;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ImportAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;

uses(TestCase::class, BootsFilament::class)->afterEach(fn () => FilamentFixture::reset());

beforeEach(function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    app()->bind(Authenticatable::class, User::class);
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));
});

it('shows why the ActionGroup child is refused', function (): void {
    $component = Livewire::test(ListOrders::class);
    $component->instance()->getTable()->recordActions(ActionGroup::make([
        Action::make('purge')->action(static fn (Order $record) => $record->delete()),
    ]));
    $component->call('mountAction', 'purge', [], ['table' => true, 'recordKey' => '1']);
    $mounted = $component->instance()->mountedActions;
    $action = null;
    $message = 'no-action';

    try {
        $action = $component->instance()->getMountedAction();
        $message = $action?->getAuthorizationResponse()->message() ?? 'null-action';
    } catch (Throwable $error) {
        $message = $error::class;
    }

    fwrite(STDERR, 'mounted='.json_encode($mounted).' auth='.$message.' exists='.(int) Order::query()->whereKey(1)->exists()."\n");
    expect(Order::query()->whereKey(1)->exists())->toBeTrue();
});

it('does not delete a hidden order through a column action', function (): void {
    OrderResource::$extras['columns'][] = static fn (): TextColumn => TextColumn::make('title')
        ->action(static fn (Order $record) => $record->delete());

    Livewire::test(ListOrders::class)->call('callTableColumnAction', 'title', '3');

    expect(Order::query()->whereKey(3)->exists())->toBeTrue();
});

it('calls ImportAction past authorization into validation', function (): void {
    OrderResource::$extras['recordActions'][] = static fn (): Action => ImportAction::make()->importer(ProbeOrderImporter::class);
    $seen = 'none';

    try {
        Livewire::test(ListOrders::class)
            ->call('mountAction', 'import', [], ['table' => true, 'recordKey' => '1'])
            ->call('callMountedAction');
        $seen = 'returned';
    } catch (Throwable $error) {
        $seen = $error::class.': '.$error->getMessage();
    }

    fwrite(STDERR, "import={$seen}\n");
    expect($seen)->not->toBe('none');
});

final class ProbeOrderImporter extends \Filament\Actions\Imports\Importer
{
    protected static ?string $model = Order::class;

    public static function getColumns(): array
    {
        return [\Filament\Actions\Imports\ImportColumn::make('number')];
    }

    public function resolveRecord(): ?Order
    {
        return new Order;
    }

    public static function getCompletedNotificationBody(\Filament\Actions\Imports\Models\Import $import): string
    {
        return 'done';
    }
}
