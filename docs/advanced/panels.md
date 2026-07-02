# Panels

A **panel** is an isolated permission namespace in AzGuard. Think of it as an independent access control domain within a single Laravel application.

::: info AzGuard Panel vs Filament Panel
If you use the `azguard/filament` package, be aware that these are two different concepts. An **AzGuard Panel** is a *logical authorization namespace* (e.g. `app`, `admin`). A **Filament Panel** is a *UI dashboard* (the sidebar, pages, etc.). They are linked by the integration package but are otherwise independent.
:::

## Why panels?

Most real-world Laravel apps have multiple distinct areas, each needing its own access rules:

| Panel | Users | Example permissions |
|---|---|---|
| `app` | End-users | `app.documents.view`, `app.comments.create` |
| `admin` | Staff/operators | `admin.users.delete`, `admin.reports.export` |
| `api` | API clients | `api.webhooks.create`, `api.data.read` |

Without panels, you'd either put everything in one namespace (collisions, confusion) or manage separate permission systems.

## Panel provider

Every panel is declared as a PHP class:

```php
// app/Guards/App/AppGuardPanelProvider.php
namespace App\Guards\App;

use AzGuard\PanelProvider;
use AzGuard\Support\Panel;
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

## Registering panels

```php
// config/az-guard.php
'panels' => [
    \App\Guards\App\AppGuardPanelProvider::class,
    \App\Guards\Admin\AdminGuardPanelProvider::class,
],
```

## How keys are constructed

When a permission `'documents.view'` is registered in the `app` panel, AzGuard stores and checks it as `app.documents.view`. This happens automatically — you never write the prefix in the enum:

```php
// In the enum
case View = 'documents.view';

// Full Gate key (constructed by AzGuard)
// 'app.documents.view'

// Usage
Gate::allows('app.documents.view');
$user->hasPermission('app.documents.view');
```

## Same user, multiple panels

A single user can have completely different roles in different panels:

```php
// EditorRole grants app.* permissions; ViewerRole grants admin.* permissions
$user->assignRole(EditorRole::class);   // 'editor' by name also works
$user->assignRole(ViewerRole::class);   // 'viewer' by name also works

// Enum case — scoped to its own panel automatically
$user->hasPermission(DocumentsPermission::Edit);   // true  (app panel)
$user->hasPermission(UsersPermission::Delete);     // false (admin panel — not granted)
```

## Listing panels

```bash
php artisan guard:doctor
# Shows all registered panels and their status
```

## Typical project layout

```
app/
  Guards/
    App/
      AppGuardPanelProvider.php
      Roles/
        EditorRole.php
        ViewerRole.php
      Documents/
        Permissions/
          DocumentsPermission.php
        Policies/
          DocumentsPolicy.php
      Users/
        Permissions/
          UsersPermission.php
    Admin/
      AdminGuardPanelProvider.php
      Roles/
        OperatorRole.php
      Users/
        Permissions/
          UsersPermission.php
```
