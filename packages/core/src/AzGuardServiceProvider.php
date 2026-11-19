<?php

declare(strict_types=1);

namespace AzGuard;

use AzGuard\Laravel\Internal\Console\Commands\CatalogCacheCommand;
use AzGuard\Laravel\Internal\Console\Commands\CatalogClearCommand;
use AzGuard\Laravel\Internal\Console\Commands\CatalogListCommand;
use AzGuard\Laravel\Internal\Console\Commands\DoctorCommand;
use AzGuard\Laravel\Internal\Console\Commands\ExplainCommand;
use AzGuard\Laravel\Internal\Console\Commands\GrantsListCommand;
use AzGuard\Laravel\Internal\Console\Commands\GrantsPruneCommand;
use AzGuard\Laravel\Internal\Console\Commands\PanelsListCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsCreateCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsDeleteCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsGrantCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsRevokeCommand;
use AzGuard\Laravel\Internal\Console\Commands\PermissionsShowCommand;
use AzGuard\Laravel\Internal\Console\Commands\RolesGrantCommand;
use AzGuard\Laravel\Internal\Console\Commands\RolesListCommand;
use AzGuard\Laravel\Internal\Console\Commands\RolesRenameKeyCommand;
use AzGuard\Laravel\Internal\Console\Commands\RolesRevokeCommand;
use AzGuard\Laravel\Internal\Console\Commands\SourcesListCommand;
use AzGuard\Laravel\Internal\Console\Commands\StateResetCommand;
use AzGuard\Laravel\Internal\Console\Commands\StorageMigrationCommand;
use AzGuard\Laravel\Registrars\AzGuardConfigurationRegistrar;
use AzGuard\Laravel\Registrars\AzGuardExtensionRegistrar;
use AzGuard\Laravel\Registrars\AzGuardLaravelRegistrar;
use AzGuard\Laravel\Registrars\AzGuardRuntimeRegistrar;
use AzGuard\Schema\AzGuardSchemaSets;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Schema\Enums\SchemaScope;

#[Api(since: '1.0')]
final class AzGuardServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        AzGuardConfigurationRegistrar::class,
        AzGuardRuntimeRegistrar::class,
        AzGuardExtensionRegistrar::class,
        AzGuardLaravelRegistrar::class,
    ];

    public function boot(): void
    {
        $this->bootPackageRegistrars();

        if ($this->app->make('config')->get('packman.schema.auto_load_migrations', true) === true) {
            foreach ($this->app->make(AzGuardSchemaSets::class)->sets() as $set) {
                if ($set->scope === SchemaScope::Central) {
                    $this->loadMigrationsFrom($set->path);
                }
            }
        }
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'azguard-migrations');
        $this->publishes([__DIR__.'/../config/azguard.php' => config_path('azguard.php')], 'azguard-config');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'azguard');
        $this->optimizes(optimize: 'azguard:catalog:cache', clear: 'azguard:catalog:clear', key: 'azguard');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CatalogCacheCommand::class, CatalogClearCommand::class, StorageMigrationCommand::class, ExplainCommand::class,
                DoctorCommand::class, PanelsListCommand::class, SourcesListCommand::class, CatalogListCommand::class,
                RolesListCommand::class, GrantsListCommand::class, PermissionsShowCommand::class, RolesGrantCommand::class,
                RolesRevokeCommand::class, PermissionsGrantCommand::class, PermissionsRevokeCommand::class,
                PermissionsCreateCommand::class, PermissionsDeleteCommand::class, RolesRenameKeyCommand::class,
                GrantsPruneCommand::class, StateResetCommand::class,
            ]);
        }
    }
}
