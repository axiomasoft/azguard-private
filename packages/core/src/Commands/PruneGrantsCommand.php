<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Support\Config;
use Illuminate\Console\Command;

/**
 * Artisan command to delete expired direct grants.
 *
 * Suitable for use with Laravel Scheduler:
 *
 *   // bootstrap/app.php or Console/Kernel.php
 *   Schedule::command('guard:prune-grants')->daily();
 *
 * @example
 *   php artisan guard:prune-grants
 *   php artisan guard:prune-grants --panel=app
 */
final class PruneGrantsCommand extends Command
{
    protected $signature = 'guard:prune-grants
        {--panel= : Restrict to a specific panel}';

    protected $description = 'Delete all expired direct grants';

    public function handle(): int
    {
        $panel = $this->option('panel');

        $grantModel = Config::directGrantModel();

        $query = $grantModel::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        if ($panel !== null && $panel !== '') {
            $query->where('panel_id', $panel);
        }

        $deleted = $query->delete();

        $suffix = $panel ? " (panel: {$panel})" : '';
        $this->info("Pruned {$deleted} expired grant(s){$suffix}.");

        return self::SUCCESS;
    }
}
