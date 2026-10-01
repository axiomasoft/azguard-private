<?php

declare(strict_types=1);

namespace AzGuard\Context\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Context\ContextGrantBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Artisan command to revoke a context-scoped grant.
 *
 * @example
 *   # revoke a specific context grant
 *   php artisan guard:context:revoke 42 app.documents.export app workspace 7
 *
 *   # revoke every context grant for this panel+context
 *   # (the permission argument is required but ignored with --all)
 *   php artisan guard:context:revoke 42 ignored app workspace 7 --all
 */
final class ContextRevokeCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:context:revoke
        {user-id        : User ID}
        {permission     : Permission key (ignored with --all)}
        {panel          : Panel ID}
        {context-type   : Context type (e.g. workspace)}
        {context-id     : Context ID}
        {--all          : Revoke all context grants for this panel+context}
        {--model=       : User model FQCN (defaults to auth.providers.users.model)}
        {--force        : Skip confirmation prompt for --all}';

    protected $description = 'Revoke context-scoped grant(s) for an AzGuard user';

    public function handle(): int
    {
        $userId = $this->argument('user-id');
        $permKey = $this->argument('permission');
        $panelId = $this->argument('panel');
        $contextType = $this->argument('context-type');
        $contextId = $this->argument('context-id');
        $all = (bool) $this->option('all');
        $modelClass = $this->resolveUserModelClass();

        /** @var Authenticatable|null $user */
        $user = $modelClass::find($userId);

        if ($user === null) {
            $this->error("User [{$modelClass}] with ID={$userId} not found.");

            return self::FAILURE;
        }

        $builder = (new ContextGrantBuilder($user))
            ->on($panelId)
            ->inContext($contextType, $contextId);

        if ($all) {
            if (! $this->option('force') && ! $this->confirm(
                "Revoke all context grants for panel [{$panelId}] context [{$contextType}:{$contextId}] from user #{$userId}?",
            )) {
                $this->line('Cancelled.');

                return self::SUCCESS;
            }

            $deleted = $builder->revokeAll();
            $this->info("Deleted {$deleted} context grant(s).");

            return self::SUCCESS;
        }

        $deleted = $builder->revoke($permKey);

        if ($deleted === 0) {
            $this->warn("Context grant [{$permKey}] not found or already revoked.");

            return self::SUCCESS;
        }

        $this->info("Context grant [{$permKey}] revoked.");

        return self::SUCCESS;
    }
}
