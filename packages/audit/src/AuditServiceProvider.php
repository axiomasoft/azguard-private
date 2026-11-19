<?php

declare(strict_types=1);

namespace AzGuard\Audit;

use AzGuard\Audit\Internal\Console\AuditMigrationCommand;
use AzGuard\Audit\Internal\Console\AuditPruneCommand;
use AzGuard\Audit\Laravel\Registrars\AuditExtensionRegistrar;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;

/**
 * The composition root of the Audit package: the publish tag `azguard-audit-migrations` of the journal migration and,
 * in the console, the audit commands. It performs no I/O and activates nothing; a panel journals only with
 * `AuditPlugin`. The journal migration is never loaded automatically: an application without the plugin has no
 * pending audit migration.
 */
#[Api(since: '1.0')]
final class AuditServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        AuditExtensionRegistrar::class,
    ];

    public function register(): void
    {
        $this->app->register(PackmanServiceProvider::class);
        $this->app->register(PluginsServiceProvider::class);
        $this->app->register(SchemaServiceProvider::class);
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        if ($this->app->make(PluginInspector::class)->facetsOf('axiomasoft.azguard-audit') === null) {
            return;
        }

        $this->bootPackageRegistrars();
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'azguard-audit-migrations');

        if ($this->app->runningInConsole()) {
            $this->app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
                $event->artisan->resolveCommands([AuditMigrationCommand::class, AuditPruneCommand::class]);
            });
        }
    }
}
