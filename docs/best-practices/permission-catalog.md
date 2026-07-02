# Permission Catalog

The **permission catalog** is the single source of truth for every valid permission key in your application. AzGuard builds it at boot time by scanning all registered permission enums for each panel.

## How it works

When a request comes in, AzGuard checks the permission key against the catalog:

- If the key **is in the catalog** → AzGuard resolves it (from roles, direct grants, wildcard)
- If the key **is not in the catalog** → AzGuard returns `null` to Laravel Gate, allowing other hooks to handle it

This means unknown permission keys are never silently denied — they pass through to your other Gate hooks.

## First-party permissions (azguard/filament)

These permissions are registered automatically when `AzGuardPlugin` is installed.

### Admin panel (`admin.*`)

| Permission key | Enum case | Description |
|---|---|---|
| `admin.az-guard.roles.view` | `AzGuardPermission::RolesView` | View the role list in Filament |
| `admin.az-guard.roles.manage` | `AzGuardPermission::RolesManage` | Create, edit, delete roles |
| `admin.az-guard.grants.view` | `AzGuardPermission::GrantsView` | View direct grants list |
| `admin.az-guard.grants.manage` | `AzGuardPermission::GrantsManage` | Create, edit, revoke grants |
| `admin.az-guard.doctor.view` | `AzGuardPermission::DoctorView` | Access the AzGuard Doctor page |

### App panel (`app.*`)

The core package does not register any `app.*` permissions. All `app.*` keys come from your application's own enums.

## Defining your own catalog

```bash
php artisan make:guard-permission App Invoices View
```

Then register the enum class in your panel provider via `permissionEnums()`:

```php
class AppGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->permissionEnums([
                DocumentsPermission::class,
                InvoicesPermission::class,  // <-- add here
            ]);
    }
}
```

## Listing the catalog

```bash
# All permissions for all panels
php artisan guard:list-permissions

# Filtered by panel (positional argument)
php artisan guard:list-permissions app
php artisan guard:list-permissions admin
```

Example output:

```
Panel: app
┌─────────────────────────────────┬───────────────────────────────────┬────────┐
│ Key                             │ Enum class                        │ Gate   │
├─────────────────────────────────┼───────────────────────────────────┼────────┤
│ app.documents.view              │ DocumentsPermission::View         │ ✓      │
│ app.documents.create            │ DocumentsPermission::Create       │ ✓      │
│ app.documents.edit              │ DocumentsPermission::Edit         │ ✓      │
│ app.documents.delete            │ DocumentsPermission::Delete       │ ✓      │
│ app.invoices.view               │ InvoicesPermission::View          │ ✓      │
│ app.invoices.export             │ InvoicesPermission::Export        │ ✗      │
└─────────────────────────────────┴───────────────────────────────────┴────────┘
```

`✗` in the Gate column means the case is marked `#[RoleOnly]` — it is not registered as a Gate ability and can only be checked via `$user->hasPermission()`.

## Validating the catalog

```bash
php artisan guard:doctor
```

The doctor reports:
- Enum cases that are registered in the catalog but missing from the database (if you use DB-backed roles)
- DB permission keys that no longer exist in any enum (orphans after a rename)
- Panels with zero registered permissions
- Role classes that reference permissions not in the catalog

## Refreshing the catalog cache

The catalog is cached at boot time. After adding new permissions, run:

```bash
php artisan guard:cache-reset
# or clear all caches:
php artisan cache:clear
```

In production, the catalog is rebuilt on the next request after `cache:clear`.
