<?php

declare(strict_types=1);

namespace AzGuard\Audit;

use AzGuard\Attributes\Api;
use AzGuard\Audit\Internal\Console\AuditMigrationCommand;
use AzGuard\Audit\Internal\Console\AuditPruneCommand;
use Illuminate\Support\ServiceProvider;

/**
 * The composition root of the Audit module: the publish tag `azguard-audit-migrations` of the journal migration and,
 * in the console, the audit commands. It performs no I/O and activates nothing; a panel journals only with
 * `AuditPlugin`. The journal migration is never loaded automatically: an application without the plugin has no
 * pending audit migration.
 */
#[Api(since: '1.0')]
final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'azguard-audit-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([AuditMigrationCommand::class, AuditPruneCommand::class]);
        }
    }
}
