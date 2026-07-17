<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesUserModel;
use AzGuard\Contracts\HasRoles as HasRolesContract;
use AzGuard\Support\Config;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Artisan command: guard:role {assign|detach} {user} {role}
 *
 * Multiplexed role-lifecycle console entry point — the console-side
 * counterpart to HasRoles::assignRole()/removeRole(), which already fire
 * RoleAttached/RoleDetached. User can be identified by ID or email; the
 * user model defaults to auth.providers.users.model (overridable via
 * --model), mirroring guard:list-scoped-roles.
 *
 * Usage:
 *   php artisan guard:role assign 1 editor
 *   php artisan guard:role detach admin@example.com editor
 *   php artisan guard:role assign 1 editor --model=App\\Models\\Admin
 */
final class RoleAssignmentCommand extends Command
{
    use ResolvesUserModel;

    protected $signature = 'guard:role
        {action  : assign | detach}
        {user    : User ID or email}
        {role    : Role name or class-string}
        {--model= : User model FQCN (defaults to auth.providers.users.model)}';

    protected $description = 'Assign or detach a role for a user (guard:role assign|detach {user} {role})';

    public function handle(): int
    {
        $action = $this->argument('action');

        if (! in_array($action, ['assign', 'detach'], strict: true)) {
            $this->components->error("Unknown action [{$action}]. Allowed: assign, detach.");

            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if (! $user instanceof HasRolesContract) {
            return self::FAILURE;
        }

        $roleArg = (string) $this->argument('role');
        $roleModel = Config::roleModel();
        $role = $roleModel::query()->where('class_name', $roleArg)->first()
            ?? $roleModel::findByName($roleArg);

        if ($role === null) {
            $this->components->error("Role [{$roleArg}] not found.");

            return self::FAILURE;
        }

        if ($action === 'assign') {
            $user->assignRole($role);
            $this->components->info("Role [{$role->name}] assigned to user [{$this->argument('user')}].");

            return self::SUCCESS;
        }

        $user->removeRole($role);
        $this->components->info("Role [{$role->name}] detached from user [{$this->argument('user')}].");

        return self::SUCCESS;
    }

    private function resolveUser(): ?HasRolesContract
    {
        $modelClass = $this->resolveUserModelClass();
        $identifier = (string) $this->argument('user');

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->components->error("Could not resolve the user model [{$modelClass}].");

            return null;
        }

        $user = str_contains($identifier, '@')
            ? $modelClass::where('email', $identifier)->first()
            : $modelClass::find($identifier);

        if (! $user instanceof Model) {
            $this->components->error("User [{$identifier}] not found.");

            return null;
        }

        if (! $user instanceof HasRolesContract) {
            $this->components->error('The user model must use the HasAzGuard (or HasRoles) trait.');

            return null;
        }

        return $user;
    }
}
