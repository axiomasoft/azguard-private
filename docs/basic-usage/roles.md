# Roles

In AzGuard, a role is a **PHP class** that declares which permissions it grants. Roles are code — not database records — which means they are version-controlled, reviewable in PRs, and always consistent.

## Static roles (recommended)

Static roles extend `BaseRole` (which implements `RoleInterface`). Their permissions are declared in code and never drift from what's deployed.

```php
// app/Guards/App/Roles/EditorRole.php
namespace App\Guards\App\Roles;

use App\Guards\App\Comments\Permissions\CommentsPermission;
use App\Guards\App\Documents\Permissions\DocumentsPermission;
use AzGuard\Roles\BaseRole;

class EditorRole extends BaseRole
{
    public function getLevel(): int { return 10; }

    public function permissions(): array
    {
        return [
            DocumentsPermission::View,
            DocumentsPermission::Create,
            DocumentsPermission::Edit,
            CommentsPermission::View,
            CommentsPermission::Create,
        ];
    }
}
```

`BaseRole` derives `getName()` from the class name (`EditorRole` → `editor`) and defaults `getLevel()` to `0`. Override either as needed. `permissions()` returns **enum cases** — the owning panel scopes each one automatically — or `['*']` for a super-admin. Full panel-prefixed string keys are also accepted. Each enum must be registered on its panel via `->permissionEnums([...])`.

### Role levels

`getLevel()` is an integer for hierarchy comparisons. Higher = higher privilege:

| Role | Level |
|---|---|
| `viewer` | 1 |
| `editor` | 10 |
| `manager` | 50 |
| `admin` | 100 |
| `super-admin` | 999 |

Levels are **not** used for permission inheritance — a `manager` does not automatically inherit `editor` permissions unless you explicitly list them. They exist for your own app logic. Each role declares its level via `getLevel()`, and the value is stored in the `level` column when roles are synced to the database, so you can compare or order by it in your own queries.

## Generating roles

```bash
php artisan make:guard-role
```

The command is interactive: it asks which panel the role belongs to and the role name, then generates the class under that panel's `Roles/` directory.

## Assigning roles

```php
// By class name (most explicit — preferred)
$user->assignRole(EditorRole::class);

// By string name
$user->assignRole('editor');

// Multiple roles at once (variadic — adds them)
$user->assignRole('editor', 'admin');

// Replace the full list in one call
$user->syncRoles([EditorRole::class, ViewerRole::class]);

// Remove a single role
$user->removeRole(EditorRole::class);
$user->removeRole('editor');

// Remove all roles
$user->syncRoles([]);
```

::: warning syncRoles([]) removes everything
`syncRoles()` always replaces the complete role list. Pass only the roles you want the user to have after the call. An empty array removes all roles.
:::

### From the console

`guard:role` mirrors `assignRole()`/`removeRole()` for console-driven workflows (deploy scripts, one-off admin tasks). The user can be identified by ID or email; the user model defaults to `auth.providers.users.model` (override with `--model`).

```bash
php artisan guard:role assign 1 editor
php artisan guard:role detach admin@example.com editor
php artisan guard:role assign 1 editor --model=App\\Models\\Admin
```

## Checking roles

```php
$user->hasRole('editor');                     // bool — by role name
$user->getRoleNames();                         // Collection<string>
$user->roles();                                // the roles() relation (Role models)
```

## Inspecting assigned roles

```php
// All role names as strings
$user->getRoleNames();            // Collection<string> — ['editor', 'viewer']

// All resolved permission keys (roles + direct grants) for a panel
$user->permissions('app');        // Collection<int, string>
```

## Query users by role

`HasAzGuard` exposes a `roles()` relation. Query it with standard Eloquent:

```php
// All users with the 'editor' role
User::whereHas('roles', fn ($q) => $q->where('name', 'editor'))->get();

// Users with any of these roles
User::whereHas('roles', fn ($q) => $q->whereIn('name', ['editor', 'admin']))->get();

// Users WITHOUT a specific role
User::whereDoesntHave('roles', fn ($q) => $q->where('name', 'editor'))->get();

// Combine with other clauses
User::whereHas('roles', fn ($q) => $q->where('name', 'editor'))
    ->where('active', true)
    ->orderBy('name')
    ->paginate();

// Eager-load roles to avoid N+1 in list views
User::with('roles')->paginate();

// Users with no roles at all
User::doesntHave('roles')->get();
```

## Dynamic (DB-backed) roles

For admin UIs where roles are managed at runtime, use the `Role` model and the `guard:role-permissions` command. Role rows live in the `roles` table; their DB-level permission keys in `az_guard_role_permissions`.

```php
use AzGuard\Models\Role;

// Create a DB role
$role = Role::create([
    'name'  => 'tenant-admin',
    'level' => 20,
]);

// Assign to a user
$user->assignRole($role);

// Inspect its DB-level permissions
$role->dbPermissions;                                   // HasMany<RolePermission>
$role->hasDbPermission('app.documents.view', 'app');    // bool
```

Manage a DB role's permission keys with Artisan:

```bash
# Add a permission key to a role for the 'app' panel
php artisan guard:role-permissions add tenant-admin app.documents.view --panel=app

# Remove one
php artisan guard:role-permissions remove tenant-admin app.documents.create --panel=app

# Replace the full list
php artisan guard:role-permissions sync tenant-admin --panel=app --keys=app.documents.view,app.documents.edit

# List a role's keys
php artisan guard:role-permissions list tenant-admin --panel=app
```

## Syncing static roles to DB

If your app uses the `roles` table as a reference (e.g., for Filament dropdowns), keep it in sync after deploying new PHP role classes:

```bash
php artisan guard:sync-roles
php artisan guard:sync-roles --panel=app
php artisan guard:sync-roles --dry-run    # preview without writing
```

This is safe to run in CI/CD pipelines.

## Listing a role's permissions

```bash
php artisan guard:role-permissions list {role} --panel=app
```

## Gotchas

**Roles are resolved by name.** `assignRole('admin')` looks the role up by its `getName()`. Assign by class (`assignRole(AdminRole::class)`) when you want to be unambiguous.

**`syncRoles([])` removes all roles.** This is intentional. Pass only the roles you want the user to have after the call.

**Role names should be unique.** Two role classes with the same `getName()` resolve to the same role record. Keep names distinct.

**Levels are not inherited.** A level-100 `SuperAdmin` does not automatically include all lower-level permissions. List them explicitly.

## Best practices

- **Use static roles as the source of truth.** Dynamic roles are useful for multi-tenant customization, but your base roles should always be in code.
- **One class per role.** `EditorRole`, `ViewerRole`, `AdminRole` — each in its own file.
- **Keep permission lists readable.** Avoid computed permission arrays or loops — the list should be scannable at a glance.
- **Use [Direct Grants](/basic-usage/direct-grants) for exceptions.** If one user needs an extra permission, grant it directly rather than creating a narrow role used by a single user.
