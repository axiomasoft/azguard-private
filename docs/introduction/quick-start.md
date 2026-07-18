# Quick Start

Get from zero to a working permission check in under 5 minutes.

## Requirements

- PHP 8.3+
- Laravel 11.x, 12.x, or 13.x
- A database supported by Laravel (MySQL, PostgreSQL, SQLite)

## 1. Install

```bash
composer require axioma-studio/azguard-core
php artisan vendor:publish --tag=az-guard-config
php artisan migrate
```

The migration creates these tables: `roles`, `model_has_roles`, `model_has_scopes`, `az_guard_role_permissions`, and `az_direct_grants`.

## 2. Add the trait to your User model

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardUser;

class User extends Authenticatable implements AzGuardUser
{
    use HasAzGuard;
}
```

The trait composes `HasRoles` and `HasPermissions`, adding `hasPermission()`, `checkPermission()`, `assignRole()`, `removeRole()`, `syncRoles()`, and `flushPermissions()`.

## 3. Register a panel

A **panel** is an isolated permission namespace — `app`, `admin`, `api`, etc.  
Create a panel provider and list it in `config/az-guard.php`:

```php
// app/Guards/App/AppGuardPanelProvider.php
namespace App\Guards\App;

use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\Panel;
use App\Guards\App\Documents\Permissions\DocumentsPermission;
use App\Guards\App\Users\Permissions\UsersPermission;

class AppGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->permissionEnums([
                DocumentsPermission::class,
                UsersPermission::class,
            ]);
    }
}
```

```php
// config/az-guard.php
'panels' => [
    \App\Guards\App\AppGuardPanelProvider::class,
],
```

## 4. Create a permission enum

```bash
php artisan make:guard-permission App Documents
```

```php
// app/Guards/App/Documents/Permissions/DocumentsPermission.php
namespace App\Guards\App\Documents\Permissions;

enum DocumentsPermission: string
{
    case View   = 'documents.view';
    case Create = 'documents.create';
    case Edit   = 'documents.edit';
    case Delete = 'documents.delete';
}
```

Enum values are unscoped; the panel prefixes them. The full Gate key is `{panel}.{permission_value}` → `app.documents.view`.

## 5. Create a role

```bash
php artisan make:guard-role
```

```php
// app/Guards/App/Roles/EditorRole.php
namespace App\Guards\App\Roles;

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
        ];
    }
}
```

`BaseRole` derives the role name from the class name (`EditorRole` → `editor`). `permissions()` returns **enum cases** — the panel scopes each one automatically, so you never spell out the `"app."` prefix. Run `php artisan guard:sync-roles` to mirror the role class into the `roles` table before assigning it.

## 6. Assign and check

```php
// Assign by class — unambiguous and refactor-safe
$user->assignRole(EditorRole::class);
$user->assignRole('editor');                       // by name also works

// ✅ Check with an enum case — scoped to the panel automatically
$user->hasPermission(DocumentsPermission::View);   // true

// Laravel's native Gate uses the full panel-prefixed key
Gate::allows('app.documents.view');                // true (Gate facade)
request()->user()->can('app.documents.view');      // true (Auth helper)
```

::: tip Enum cases vs. string keys
An enum case (`DocumentsPermission::View`) is scoped to the panel automatically and is refactor-safe. A string must be the full panel-prefixed key (`'app.documents.view'`). Laravel's Gate (`Gate::allows()`, `$user->can()`) works with the full string key.
:::

## Verify the setup

```bash
php artisan guard:doctor
```

The doctor checks:
- No duplicate Gate abilities across policy classes
- Every enum permission case has a matching `#[GateAbility]` policy method
- Roles reference only known permissions
- No orphan policies (classes with no `#[GateAbility]` methods)
- No stale `scope_class` values left over in `model_has_scopes`

## Next steps

- [Panels](/advanced/panels) — understand `app` vs `admin` isolation
- [Permissions](/basic-usage/permissions) — naming conventions, `#[RoleOnly]`, frontend abilities
- [Roles](/basic-usage/roles) — static and dynamic (DB-backed) roles
- [HTTP Access](/basic-usage/http-access) — `#[CheckPermission]` on controllers and middleware
- [Direct Grants](/basic-usage/direct-grants) — per-user permissions without a role
- [Super-Admin](/basic-usage/super-admin) — wildcard access bypass
