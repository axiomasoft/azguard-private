<?php

declare(strict_types=1);

namespace AzGuard\Context\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Context\ContextGrantBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Artisan command to issue a context-scoped grant to a user.
 *
 * @example
 *   php artisan guard:context:grant 42 app.documents.export app workspace 7
 *   php artisan guard:context:grant 42 app.documents.export app workspace 7 --model=App\\Models\\Admin
 */
final class ContextGrantCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:context:grant
        {user-id        : User ID}
        {permission     : Permission key (e.g. app.documents.export)}
        {panel          : Panel ID}
        {context-type   : Context type (e.g. workspace)}
        {context-id     : Context ID}
        {--model=       : User model FQCN (defaults to auth.providers.users.model)}';

    protected $description = 'Issue a context-scoped grant to an AzGuard user';

    public function handle(): int
    {
        $userId = $this->argument('user-id');
        $permKey = $this->argument('permission');
        $panelId = $this->argument('panel');
        $contextType = $this->argument('context-type');
        $contextId = $this->argument('context-id');
        $modelClass = $this->resolveUserModelClass();

        /** @var Authenticatable|null $user */
        $user = $modelClass::find($userId);

        if ($user === null) {
            $this->error("User [{$modelClass}] with ID={$userId} not found.");

            return self::FAILURE;
        }

        (new ContextGrantBuilder($user))
            ->on($panelId)
            ->inContext($contextType, $contextId)
            ->grant($permKey);

        $this->table(
            ['User ID', 'Model', 'Permission', 'Panel', 'Context type', 'Context ID'],
            [[$user->getAuthIdentifier(), $modelClass, $permKey, $panelId, $contextType, $contextId]],
        );

        $this->info('Context grant issued.');

        return self::SUCCESS;
    }
}
