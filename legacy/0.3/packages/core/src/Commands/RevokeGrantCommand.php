<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Grants\GrantBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Artisan command to revoke a direct grant.
 *
 * @example
 *   # revoke a specific grant
 *   php artisan guard:revoke-grant 42 app.documents.export app
 *
 *   # revoke all grants for a panel
 *   php artisan guard:revoke-grant 42 -- app --all
 */
final class RevokeGrantCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:revoke-grant
        {user-id        : User ID}
        {permission     : Permission key (ignored with --all)}
        {panel          : Panel ID}
        {--all          : Revoke all grants for this panel}
        {--model=       : User model FQCN (defaults to auth.providers.users.model)}
        {--force        : Skip confirmation prompt for --all}';

    protected $description = 'Revoke direct grant(s) for an AzGuard user';

    public function handle(): int
    {
        $userId = $this->argument('user-id');
        $permKey = $this->argument('permission');
        $panelId = $this->argument('panel');
        $all = (bool) $this->option('all');
        $modelClass = $this->resolveUserModelClass();

        /** @var Authenticatable|null $user */
        $user = $modelClass::find($userId);

        if ($user === null) {
            $this->error("User [{$modelClass}] with ID={$userId} not found.");

            return self::FAILURE;
        }

        $builder = (new GrantBuilder($user))->on($panelId);

        if ($all) {
            if (! $this->option('force') && ! $this->confirm(
                "Revoke all grants for panel [{$panelId}] from user #{$userId}?",
            )) {
                $this->line('Cancelled.');

                return self::SUCCESS;
            }

            $deleted = $builder->revokeAll();
            $this->info("Deleted {$deleted} grant(s) for panel [{$panelId}].");

            return self::SUCCESS;
        }

        $deleted = $builder->revoke($permKey);

        if ($deleted === 0) {
            $this->warn("Grant [{$permKey}] not found or already revoked.");

            return self::SUCCESS;
        }

        $this->info("Grant [{$permKey}] revoked.");

        return self::SUCCESS;
    }
}
