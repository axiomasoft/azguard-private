<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Grants\GrantBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Artisan command to issue a direct grant to a user.
 *
 * @example
 *   php artisan guard:grant 42 app.documents.export app --ttl=3600
 *   php artisan guard:grant 42 app.documents.export app --model=App\\Models\\Admin
 */
final class GrantCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:grant
        {user-id        : User ID}
        {permission     : Permission key (e.g. app.documents.export)}
        {panel          : Panel ID}
        {--ttl=         : TTL in seconds (omit for no expiry)}
        {--model=       : User model FQCN (defaults to auth.providers.users.model)}';

    protected $description = 'Issue a direct grant to an AzGuard user';

    public function handle(): int
    {
        $userId = $this->argument('user-id');
        $permKey = $this->argument('permission');
        $panelId = $this->argument('panel');
        $ttl = $this->option('ttl') !== null ? (int) $this->option('ttl') : null;
        $modelClass = $this->resolveUserModelClass();

        /** @var Authenticatable|null $user */
        $user = $modelClass::find($userId);

        if ($user === null) {
            $this->error("User [{$modelClass}] with ID={$userId} not found.");

            return self::FAILURE;
        }

        $builder = (new GrantBuilder($user))->on($panelId);

        if ($ttl !== null) {
            $builder = $builder->ttl($ttl);
        }

        $grant = $builder->grant($permKey);

        $expiresAt = $grant->expires_at?->toDateTimeString() ?? 'never';

        $this->table(
            ['User ID', 'Model', 'Permission', 'Panel', 'Expires at'],
            [[
                $user->getAuthIdentifier(),
                $modelClass,
                $permKey,
                $panelId,
                $expiresAt,
            ]],
        );

        $this->info('Grant issued.');

        return self::SUCCESS;
    }
}
