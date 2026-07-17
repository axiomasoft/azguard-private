<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Concerns\HasRoles;
use AzGuard\Roles\SuperAdminRole;
use AzGuard\Support\Config;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Promote a user to super-admin: the SuperAdminRole grants '*', which
 * short-circuits every check via Gate::before(). The fastest path to a working
 * login right after install.
 */
final class SuperAdminCommand extends Command
{
    protected $signature = 'guard:super-admin {--user= : ID of the user to promote}';

    protected $description = 'Grant a user the super-admin role (wildcard access)';

    public function handle(): int
    {
        $userId = $this->option('user') ?: $this->ask('User id to promote to super-admin');

        if (! is_string($userId) || $userId === '') {
            $this->components->error('No user id provided.');

            return self::FAILURE;
        }

        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! is_subclass_of($userModel, Model::class)) {
            $this->components->error('Could not resolve the auth user model (auth.providers.users.model).');

            return self::FAILURE;
        }

        $user = $userModel::query()->find($userId);

        if (! $user instanceof Model) {
            $this->components->error("User [{$userId}] not found.");

            return self::FAILURE;
        }

        if (! in_array(HasRoles::class, class_uses_recursive($user), strict: true)) {
            $this->components->error('The user model must use the HasAzGuard (or HasRoles) trait.');

            return self::FAILURE;
        }

        $superAdmin = new SuperAdminRole;

        $roleModel = Config::roleModel();

        $role = $roleModel::query()->firstOrCreate(
            ['name' => $superAdmin->getName()],
            ['class_name' => SuperAdminRole::class, 'level' => $superAdmin->getLevel()],
        );

        // Attach via the role's (typed) inverse relation so we don't depend on
        // the user model's trait methods being statically known here.
        $role->users()->syncWithoutDetaching([$user->getKey()]);

        $this->components->info("User [{$userId}] is now a super-admin (role '{$role->name}').");

        return self::SUCCESS;
    }
}
