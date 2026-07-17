<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Support\Config;
use Illuminate\Console\Command;

/**
 * Artisan command: guard:list-scoped-roles {user}
 *
 * Lists all entity-scoped role assignments for a given user.
 * User can be identified by ID or email.
 *
 * Usage:
 *   php artisan guard:list-scoped-roles 1
 *   php artisan guard:list-scoped-roles admin@example.com
 *   php artisan guard:list-scoped-roles 1 --entity="App\Models\Project"
 */
class ListScopedRolesCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:list-scoped-roles
                            {user : User ID or email}
                            {--entity= : Filter by entity type (FQCN, e.g. App\\Models\\Project)}
                            {--model=  : User model FQCN (defaults to auth.providers.users.model)}';

    protected $description = 'List all entity-scoped role assignments for a user';

    public function handle(): int
    {
        $userModelClass = $this->resolveUserModelClass();
        $identifier = (string) $this->argument('user');

        // Resolve by email when the identifier looks like one, otherwise by
        // primary key — works for int, ULID and UUID keys alike.
        $user = str_contains($identifier, '@')
            ? $userModelClass::where('email', $identifier)->first()
            : $userModelClass::find($identifier);

        if ($user === null) {
            $this->error("User [{$identifier}] not found.");

            return self::FAILURE;
        }

        $scopeModel = Config::scopeModel();

        $query = $scopeModel::query()
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->whereNotNull('role_id')
            ->with('role');

        if ($entityFilter = $this->option('entity')) {
            $query->where('scope_entity_type', $entityFilter);
        }

        $scopes = $query->get();

        if ($scopes->isEmpty()) {
            $this->warn("User [{$identifier}] has no scoped roles.");

            return self::SUCCESS;
        }

        $entityType = $scopes->first()?->scope_entity_type;
        $entityLabel = $entityType !== null ? class_basename($entityType) : '';
        $this->info("Scoped roles for user: <comment>{$identifier}</comment>".($entityLabel !== '' ? " (entity: {$entityLabel})" : ''));
        $this->line('');

        $rows = $scopes->map(fn ($scope): array => [
            $scope->role?->name ?? '—',
            $scope->scope_entity_type !== null ? class_basename($scope->scope_entity_type) : '—',
            (string) ($scope->scope_entity_id ?? '—'),
            $scope->scope_class !== null ? class_basename($scope->scope_class) : '—',
        ])->toArray();

        $this->table(
            ['Role', 'Entity Type', 'Entity ID', 'Scope Class'],
            $rows,
        );

        return self::SUCCESS;
    }
}
