<?php

declare(strict_types=1);

use AzGuard\Filament\Authorization\AzGuardFilamentSurfaceGate;
use AzGuard\Panels\Reads;
use AzGuard\Tests\Fixtures\Filament\BootsFilament;
use AzGuard\Tests\Fixtures\Filament\Exports\OrderExporter;
use AzGuard\Tests\Fixtures\Filament\FilamentFixture;
use AzGuard\Tests\Fixtures\Filament\GateWorld;
use AzGuard\Tests\Fixtures\Filament\Guards\ManagedRelationPermission;
use AzGuard\Tests\Fixtures\Filament\Models\Order;
use AzGuard\Tests\Fixtures\Filament\Models\Product;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ManagedProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\RelationManagers\ProductsRelationManager;
use AzGuard\Tests\Fixtures\Filament\Resources\Pages\ListOrders;
use AzGuard\Tests\TestCase;
use Filament\Actions\DetachAction;
use Filament\Actions\ExportAction;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Livewire\Livewire;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Filament\PackmanFilamentServiceProvider;
use Packman\Filament\Security\Authorization\AuthorizesActions as PackmanAuthorizesActions;
use Packman\Filament\Security\Authorization\AuthorizesExports as PackmanAuthorizesExports;
use Packman\Filament\Security\Authorization\FilamentGate as PackmanFilamentGate;
use Packman\Filament\Security\Surface\RefusesSurfaceBoot;
use Packman\Filament\Security\Surface\RefusesSurfaceCalls;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('passes Packman boot and call guards for an authorized AzGuard relation manager', function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'products.view_any', 'products.view']));

    $test = Livewire::test(ProductsRelationManager::class, [
        'ownerRecord' => Order::query()->findOrFail(1),
        'pageClass' => ListOrders::class,
    ])->assertSuccessful();

    $hook = new RefusesSurfaceBoot;
    $hook->setComponent($test->instance());
    $hook->mount();
    $hook->hydrate();
    (new RefusesSurfaceCalls)($test->instance());
    $test->call('$refresh')->assertSuccessful()
        ->assertCanSeeTableRecords(Product::query()->whereKey(1)->get())
        ->assertCanNotSeeTableRecords(Product::query()->whereKey(2)->get());

    GateWorld::revoke('products.view_any');
    expect(fn () => $hook->hydrate())->toThrow(HttpException::class)
        ->and(fn () => (new RefusesSurfaceCalls)($test->instance()))->toThrow(HttpException::class);
});

it('honors azguardRelationAbility even when Packman AuthorizesActions configures the action last', function (): void {
    GateWorld::prepare();
    FilamentFixture::$relations = [ManagedProductsRelationManager::class];
    FilamentFixture::$guardPermissions[] = ManagedRelationPermission::class;
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.update', 'products.view_any', 'products.view']));

    $test = Livewire::test(ManagedProductsRelationManager::class, [
        'ownerRecord' => Order::query()->findOrFail(1),
        'pageClass' => ListOrders::class,
    ])->assertSuccessful();

    $action = DetachAction::make()->livewire($test->instance())->record(Product::query()->findOrFail(1));
    PackmanAuthorizesActions::configure($action);

    expect($action->isAuthorized())->toBeFalse();

    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.manage_products', 'products.view_any', 'products.view']));
    expect($action->isAuthorized())->toBeTrue();
});

it('allows valid AzGuard exports and refuses replica reads when Packman AuthorizesExports runs', function (): void {
    GateWorld::prepare();
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    $page = Livewire::test(ListOrders::class)->assertSuccessful();
    $action = ExportAction::make('export')->exporter(OrderExporter::class)->livewire($page->instance())->table($page->instance()->getTable());

    (new PackmanAuthorizesExports)($action);

    FilamentFixture::$reads = Reads::Default;
    $this->bootFilament();
    GateWorld::seed();
    GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

    $replicaPage = Livewire::test(ListOrders::class)->assertSuccessful();
    $replicaAction = ExportAction::make('export')->exporter(OrderExporter::class)->livewire($replicaPage->instance())->table($replicaPage->instance()->getTable());

    expect(fn () => (new PackmanAuthorizesExports)($replicaAction))->toThrow(HttpException::class);
});

it('preserves AzGuardFilamentSurfaceGate, azguardRelationAbility, and canExport in both PackmanFilamentServiceProvider orders', function (bool $packmanFirst): void {
    $original = app();
    /** @var list<Application> $created */
    $created = [];

    $bootOrderedWorld = static function () use ($packmanFirst, &$created): Application {
        $case = new class('filament-provider-order-world') extends TestCase
        {
            use BootsFilament {
                getPackageProviders as filamentPackageProviders;
            }

            public bool $packmanFirst;

            public function seedFixtureTables(): void
            {
                $this->createFixtureTables();
            }

            protected function getPackageProviders($app): array
            {
                $providers = $this->filamentPackageProviders($app);

                return $this->packmanFirst
                    ? [PackmanFilamentServiceProvider::class, ...$providers]
                    : [...$providers, PackmanFilamentServiceProvider::class];
            }
        };
        $case->packmanFirst = $packmanFirst;

        $app = $case->createApplication();
        $created[] = $app;
        $case->seedFixtureTables();

        return $app;
    };

    try {
        GateWorld::prepare();
        FilamentFixture::$relations = [ManagedProductsRelationManager::class];
        FilamentFixture::$guardPermissions[] = ManagedRelationPermission::class;

        $app = $bootOrderedWorld();
        expect($app->make(FilamentSurfaceGate::class))->toBeInstanceOf(AzGuardFilamentSurfaceGate::class);

        GateWorld::seed();
        GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.update', 'products.view_any', 'products.view']));

        expect(PackmanFilamentGate::relationAbility(ManagedProductsRelationManager::class))->toBe('manage_products')
            ->and(PackmanFilamentGate::canExport())->toBeTrue();

        $relation = Livewire::test(ManagedProductsRelationManager::class, [
            'ownerRecord' => Order::query()->findOrFail(1),
            'pageClass' => ListOrders::class,
        ])->assertSuccessful();

        $detach = DetachAction::make()->livewire($relation->instance())->record(Product::query()->findOrFail(1));
        expect($detach->isAuthorized())->toBeFalse();

        GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view', 'orders.manage_products', 'products.view_any', 'products.view']));
        expect($detach->isAuthorized())->toBeTrue();

        $page = Livewire::test(ListOrders::class)->assertSuccessful();
        $export = ExportAction::make('export')->exporter(OrderExporter::class)->livewire($page->instance())->table($page->instance()->getTable());
        (new PackmanAuthorizesExports)($export);

        FilamentFixture::$reads = Reads::Default;
        $replicaApp = $bootOrderedWorld();
        expect($replicaApp->make(FilamentSurfaceGate::class))->toBeInstanceOf(AzGuardFilamentSurfaceGate::class);

        GateWorld::seed();
        GateWorld::serve(GateWorld::grant(['orders.view_any', 'orders.view']));

        expect(PackmanFilamentGate::canExport())->toBeFalse();

        $replicaPage = Livewire::test(ListOrders::class)->assertSuccessful();
        $replicaExport = ExportAction::make('export')->exporter(OrderExporter::class)->livewire($replicaPage->instance())->table($replicaPage->instance()->getTable());
        expect(fn () => (new PackmanAuthorizesExports)($replicaExport))->toThrow(HttpException::class);
    } finally {
        foreach ($created as $booted) {
            foreach ($booted['db']->getConnections() as $connection) {
                if ($connection instanceof Connection) {
                    $connection->disconnect();
                }
            }
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($original);
        Container::setInstance($original);
    }
})->with(['Packman first' => true, 'Packman last' => false]);
