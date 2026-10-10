# Upgrading

## From 0.3 to 0.7.0

Version 0.3 (`axioma-studio/azguard-core`, `-context`, `-filament`, tag `v0.3.0`) was never published on
Packagist. Only upgrades from 0.3 are covered; upgrade an older install to 0.3 first. 0.7.0 is a redesign under new package names. There is no in-place upgrade: install 0.7.0, port the code,
then copy the role assignments.

### What changed

| 0.3 | 0.7.0 |
|---|---|
| `axioma-studio/azguard-core`, `-filament`, `-context` | `axiomasoft/azguard`, `axiomasoft/azguard-filament`. Tenants and scopes are in the core |
| `config/az-guard.php` | `config/azguard.php` (`php artisan azguard:install`) |
| `roles` table and role rows | Roles are PHP classes only. Grants are stored in `azg_role_grants` |
| `model_has_roles`, `model_has_scopes` | `azg_role_grants`, with an optional assignment scope per grant |
| `az_direct_grants`, `az_guard_role_permissions` | `azg_permission_grants`. A role's permissions are declared on the role class |
| `assignRole()`, `removeRole()` | `grantRole()`, `revokeRole()` (with `on:` and `until:`) |
| `assignScopedRole()`, `hasScopedRole()` | `grantRole($role, on: $team)`, `hasRole($role, on: $team)` |
| `grant()`, `revoke()`, `hasGrant()` | `grantPermission()`, `revokePermission()`, `hasPermission()` |
| `checkPermission()` | `hasPermission()` or `AzGuard::authorize()` |
| `check.access` middleware | `azguard.panel:{id}` and `azguard.can:{permission}` |
| `grant_sources`, `GrantSource` | Panel sources: `permissions([...])`, `ProvidesGrants` ([Sources](/advanced/sources)) |
| `guard:*` commands | `azguard:*` ([Commands](/reference/commands)) |
| `cache.store = 'array'` | `cache.store = null` (memoized for the request) or a Laravel store |

### Steps

1. Require `axiomasoft/azguard` and run `php artisan azguard:install --panel=Admin --migrate`.
2. Move permission enums into `app/Guards/{Panel}/Permissions` and roles into `Roles`. Give every role an
   explicit `#[Role('key')]`. Run `php artisan azguard:doctor` until it reports no errors.
3. Copy role assignments. The script below was tested against the 0.3 schema. Map every old role name to a
   0.7.0 role class explicitly, so an unknown name stops the migration instead of being dropped silently:

```php
use AzGuard\Facades\AzGuard;
use AzGuard\Support\ModelKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

$map = ['editor' => EditorRole::class, 'super-admin' => SuperAdminRole::class];

AzGuard::actingAs('migration from 0.3', function () use ($map): void {
    DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->select('roles.name', 'model_has_roles.model_type', 'model_has_roles.model_id')
        ->orderBy('model_has_roles.model_id')
        ->lazy()
        ->each(function (object $row) use ($map): void {
            $role = $map[$row->name] ?? throw new RuntimeException("No 0.7.0 role for {$row->name}");
            $class = Relation::getMorphedModel($row->model_type) ?? $row->model_type;

            if (! is_subclass_of($class, Model::class)) {
                throw new RuntimeException("Unknown subject type {$row->model_type}");
            }
            $subject = ModelKey::find((new $class)->newQuery(), (string) $row->model_id);

            if ($subject === null) {
                logger()->warning('azguard migration: subject not found', (array) $row);

                return;
            }

            $subject->grantRole($role);   // repeatable: a second run changes nothing
        });
});
```

   Scoped roles (`model_has_scopes`) and direct grants work the same way, with
   `grantRole($role, on: $scopeModel)` and `grantPermission($permission)`.

4. Keep the old tables until the application has run on 0.7.0 for a while, then drop them.
