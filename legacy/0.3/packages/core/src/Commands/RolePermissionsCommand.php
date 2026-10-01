<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Configuration\Config;
use AzGuard\Models\Role;
use AzGuard\Registry\Contracts\PermissionCatalog;
use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;
use AzGuard\Roles\RolePermissionConnectionException;
use AzGuard\Roles\RolePermissionSelection;
use AzGuard\Roles\RolePermissionSynchronizer;
use Illuminate\Console\Command;

/**
 * Manage DB-level role permissions (az_guard_role_permissions).
 *
 * Examples:
 *   php artisan guard:role-permissions list editor
 *   php artisan guard:role-permissions list editor --panel=app
 *   php artisan guard:role-permissions add  editor app.documents.view --panel=app
 *   php artisan guard:role-permissions remove editor app.documents.view --panel=app
 *   php artisan guard:role-permissions sync editor --panel=app --keys="app.x.view,app.x.edit"
 */
class RolePermissionsCommand extends Command
{
    protected $signature = 'guard:role-permissions
        {action          : list | add | remove | sync}
        {role            : Role name or its ID}
        {permission_key? : Permission key (for add / remove)}
        {--panel=app     : Panel ID}
        {--keys=         : Comma-separated list of keys (for sync)}
        {--force         : Skip confirmation prompt for sync}';

    protected $description = 'Manage DB-level role permissions (role_permissions)';

    public function handle(PermissionCatalog $catalog, RolePermissionSynchronizer $synchronizer): int
    {
        $action = $this->argument('action');
        $roleArg = $this->argument('role');
        $panelId = (string) $this->option('panel');

        $roleModel = Config::roleModel();

        $role = is_numeric($roleArg)
            ? $roleModel::find((int) $roleArg)
            : $roleModel::where('name', $roleArg)->first();

        if ($role === null) {
            $this->error("Role [{$roleArg}] not found in the database.");

            return self::FAILURE;
        }

        return match ($action) {
            'list' => $this->actionList($role, $panelId),
            'add' => $this->actionAdd($role, $panelId, $synchronizer),
            'remove' => $this->actionRemove($role, $panelId, $synchronizer),
            'sync' => $this->actionSync($role, $panelId, $catalog, $synchronizer),
            default => $this->invalidAction($action),
        };
    }

    // -------------------------------------------------------------------------

    private function actionList(Role $role, string $panelId): int
    {
        $query = $role->dbPermissions()->where('panel_id', $panelId);
        $perms = $query->get(['permission_key', 'panel_id', 'created_at']);

        $this->line('');
        $this->info("DB permissions for role <comment>{$role->name}</comment> (panel: {$panelId}):");

        if ($perms->isEmpty()) {
            $this->warn('  No permissions assigned.');

            return self::SUCCESS;
        }

        $this->table(
            ['Permission Key', 'Panel', 'Assigned At'],
            $perms->map(fn ($p): array => [
                $p->permission_key,
                $p->panel_id,
                $p->created_at->toDateTimeString(),
            ])->all(),
        );

        $this->line('<fg=gray>Total: '.$perms->count().' record(s)</>');

        return self::SUCCESS;
    }

    private function actionAdd(Role $role, string $panelId, RolePermissionSynchronizer $synchronizer): int
    {
        $key = $this->argument('permission_key');

        if (! is_string($key) || $key === '') {
            $this->error('Specify a permission_key.');

            return self::FAILURE;
        }

        try {
            $result = $synchronizer->sync(
                role: $role,
                selection: RolePermissionSelection::singleKey(panel: $panelId, key: $key, present: true),
            );
        } catch (InvalidPermissionKeyException|RolePermissionConnectionException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result->changed()) {
            $this->warn("Permission [{$key}] already assigned to role [{$role->name}] (panel: {$panelId}).");

            return self::SUCCESS;
        }

        $this->info("Added: [{$key}] → role [{$role->name}] (panel: {$panelId}).");

        return self::SUCCESS;
    }

    private function actionRemove(Role $role, string $panelId, RolePermissionSynchronizer $synchronizer): int
    {
        $key = $this->argument('permission_key');

        if (! is_string($key) || $key === '') {
            $this->error('Specify a permission_key.');

            return self::FAILURE;
        }

        try {
            $result = $synchronizer->sync(
                role: $role,
                selection: RolePermissionSelection::singleKey(panel: $panelId, key: $key, present: false),
            );
        } catch (RolePermissionConnectionException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result->changed()) {
            $this->warn("Permission [{$key}] not found on role [{$role->name}] (panel: {$panelId}).");

            return self::SUCCESS;
        }

        $this->info("Removed: [{$key}] from role [{$role->name}] (panel: {$panelId}).");

        return self::SUCCESS;
    }

    private function actionSync(Role $role, string $panelId, PermissionCatalog $catalog, RolePermissionSynchronizer $synchronizer): int
    {
        $keysRaw = (string) $this->option('keys');

        if ($keysRaw === '') {
            $this->error('Specify --keys="key1,key2,..." for sync.');

            return self::FAILURE;
        }

        $newKeys = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $keysRaw)))));
        $unknownKeys = array_values(array_filter($newKeys, fn (string $key): bool => ! $catalog->has($panelId, $key)));

        if ($unknownKeys !== []) {
            $this->error(sprintf(
                'Unknown permission key(s) for panel [%s]: %s.',
                $panelId,
                implode(', ', $unknownKeys),
            ));

            return self::FAILURE;
        }

        $existing = $role->dbPermissions()
            ->where('panel_id', $panelId)
            ->pluck('permission_key')
            ->all();

        $toAdd = array_values(array_diff($newKeys, $existing));
        $toRemove = array_values(array_diff($existing, $newKeys));

        if ($toAdd === [] && $toRemove === []) {
            $this->info('Permissions already in sync — no changes.');

            return self::SUCCESS;
        }

        $this->line('');

        if ($toAdd !== []) {
            $this->line('<fg=green>+ To add:</>  '.implode(', ', $toAdd));
        }

        if ($toRemove !== []) {
            $this->line('<fg=red>- To remove:</> '.implode(', ', $toRemove));
        }
        $this->line('');

        if (! $this->option('force') && ! $this->confirm('Apply changes?')) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $synchronizer->sync(
                role: $role,
                selection: RolePermissionSelection::panelReplacement(panel: $panelId, keys: $newKeys),
            );
        } catch (InvalidPermissionKeyException|RolePermissionConnectionException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Sync complete: +%d added, -%d removed (role: %s, panel: %s).',
            $result->added,
            $result->removed,
            $role->name,
            $panelId,
        ));

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action [{$action}]. Allowed: list, add, remove, sync.");

        return self::FAILURE;
    }
}
