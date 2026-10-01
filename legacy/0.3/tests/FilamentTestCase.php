<?php

declare(strict_types=1);

namespace AzGuard\Tests;

use AzGuard\Filament\AzGuardFilamentServiceProvider;
use AzGuard\Filament\Permissions\PermissionDiscovery;
use AzGuard\Filament\Permissions\PermissionSubject;
use AzGuard\Tests\Stubs\AdminPanelProvider;
use AzGuard\Tests\Stubs\Filament\GuardedRevenueWidget;
use AzGuard\Tests\Stubs\Filament\GuardedSettingsPage;
use AzGuard\Tests\Stubs\Project;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Livewire\LivewireServiceProvider;

/**
 * TestCase wiring the Filament package against an 'admin' panel whose resource
 * permissions are produced by a fixed discovery (one Project-backed resource),
 * so the database-source catalog + enforcement can be exercised without
 * bootstrapping a real Filament panel.
 */
class FilamentTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            InfolistsServiceProvider::class,
            WidgetsServiceProvider::class,
            SchemasServiceProvider::class,
            FilamentServiceProvider::class,
            AzGuardFilamentServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('az-guard.panels', [AdminPanelProvider::class]);
        $app['config']->set('az-guard-filament.panel', 'admin');
        $app['config']->set('az-guard-filament.source', 'database');
        $app['config']->set('az-guard-filament.abilities', ['view_any', 'view', 'create']);

        $app->singleton(PermissionDiscovery::class, fn (): PermissionDiscovery => new class implements PermissionDiscovery
        {
            public function subjects(string $panelId): array
            {
                return [
                    new PermissionSubject('Project', 'Projects', ['view_any', 'view', 'create'], Project::class),
                    // Discovered custom page/widget subjects: their keys must be
                    // catalogued so a granted page/widget permission survives the
                    // resolver's catalog filter and the F13 traits can enforce it.
                    new PermissionSubject('GuardedSettingsPage', 'Pages', ['view'], GuardedSettingsPage::class),
                    new PermissionSubject('GuardedRevenueWidget', 'Widgets', ['view'], GuardedRevenueWidget::class),
                ];
            }
        });
    }
}
