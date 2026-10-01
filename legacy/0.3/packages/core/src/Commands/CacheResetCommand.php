<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Configuration\Config;
use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Runtime\ScopedRoleCache;
use Illuminate\Console\Command;
use Throwable;

class CacheResetCommand extends Command
{
    protected $signature = 'guard:cache-reset {--force : Skip the confirmation prompt}';

    protected $description = 'Advance the AzGuard permission-state revision without flushing the cache store';

    public function handle(PermissionStateRevision $permissionState, PermissionCache $permissionCache, ScopedRoleCache $scopedRoleCache): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'This advances the AzGuard permission-state revision and clears local request caches. The configured cache store is not flushed. Continue?',
        )) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        try {
            $before = $permissionState->current();
            $revision = $permissionState->connection()->transaction(
                fn (): int => $permissionState->bump(),
            );
        } catch (Throwable $e) {
            $this->error('Failed to advance permission-state revision: '.$e->getMessage());

            return self::FAILURE;
        }

        $permissionCache->forgetAll();
        $scopedRoleCache->flush();

        $generation = Config::cacheGeneration();
        $this->info("AzGuard permission cache reset. revision={$revision} generation={$generation}");
        $this->line("Previous revision was {$before}.");

        return self::SUCCESS;
    }
}
