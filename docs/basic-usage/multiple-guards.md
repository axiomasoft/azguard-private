# Multiple Guards

AzGuard supports applications that use more than one authentication guard — for example, a `web` guard for regular users and an `api` guard for mobile clients, or a separate `admin` guard for a back-office panel.

## How it works

Each guard resolves its own set of panels. The permission namespace (`app.*`, `admin.*`) is bound to a panel, and a panel is bound to one or more guards. This gives you full isolation with zero cross-contamination.

```
web guard   → App panel   → app.documents.view, app.posts.edit …
admin guard → Admin panel → admin.users.ban, admin.roles.manage …
```

## Configuration

Register one panel provider per area in your `config/az-guard.php`. Each panel's `id()` becomes its permission prefix:

```php
'panels' => [
    \App\Guards\App\AppGuardPanelProvider::class,    // id('app')   → app.*
    \App\Guards\Admin\AdminGuardPanelProvider::class,  // id('admin') → admin.*
],
```

## Checking permissions for a specific panel

```php
// Default: resolves against the current panel (set by the azguard.panel middleware)
$user->hasPermission(DocumentsPermission::View);

// Explicit panel override — pass the panel id as the second argument
$user->hasPermission(AdminUsersPermission::Ban, 'admin');
```

## Middleware with guards

```php
// Apply guard-specific permission check in routes
Route::middleware(['auth:admin', 'can:admin.users.ban'])
    ->group(function () {
        Route::post('/admin/users/{user}/ban', BanUserController::class);
    });
```

## Blade directives with guards

```blade
@can('admin.users.ban')
    <button>Ban user</button>
@endcan
```

Blade `@can` resolves against the current panel automatically. To check a different panel, resolve the boolean in the controller with `$user->hasPermission($permission, $panelId)` and pass it to the view.

::: tip
See [Panels](/advanced/panels) for the full panel configuration reference.
:::
