<?php

declare(strict_types=1);

use AzGuard\Filament\Authorization\AzGuardFilamentSurfaceGate;
use AzGuard\Filament\AzGuardFilamentServiceProvider;
use AzGuard\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Filament\PackmanFilamentServiceProvider;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Registry\PluginInspector;

it('keeps the AzGuard surface gate in both discovery orders after compiling all five plugins', function (bool $packmanFirst): void {
    $original = app();
    $case = new class('filament-provider-order') extends TestCase
    {
        public bool $packmanFirst;

        protected function getPackageProviders($app): array
        {
            $providers = parent::getPackageProviders($app);

            return $this->packmanFirst
                ? [PackmanFilamentServiceProvider::class, ...$providers]
                : [...$providers, PackmanFilamentServiceProvider::class];
        }
    };
    $case->packmanFirst = $packmanFirst;

    try {
        $app = $case->createApplication();
        $plugins = array_column($app->make(PluginInspector::class)->describe(), 'id');

        expect($plugins)->toHaveCount(5)->toContain(
            'axiomasoft.azguard', 'axiomasoft.azguard-scopes', 'axiomasoft.azguard-audit',
            'axiomasoft.azguard-filament', 'axiomasoft.azguard-devtools',
        )->and($app->getProvider(AzGuardFilamentServiceProvider::class))->not->toBeNull()
            ->and($app->getProvider(PackmanFilamentServiceProvider::class))->not->toBeNull()
            ->and($app->make(FilamentSurfaceGate::class))->toBeInstanceOf(AzGuardFilamentSurfaceGate::class)
            ->and($app->make(Capabilities::class)->require(FilamentSurfaceGate::class))->toBeInstanceOf(AzGuardFilamentSurfaceGate::class);

        // Also cover a late registration after the singleton and plugin graph have already resolved.
        $gate = $app->make(FilamentSurfaceGate::class);
        $app->register(PackmanFilamentServiceProvider::class, force: true);
        expect($app->make(FilamentSurfaceGate::class))->toBe($gate);
    } finally {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($original);
        Container::setInstance($original);
    }
})->with(['Packman first' => true, 'Packman last' => false]);
