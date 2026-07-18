# Multiple Guards

AzGuard supports applications that use more than one authentication guard — for example, a `web` guard for regular users and an `api` guard for mobile clients, or a separate `admin` guard for a back-office panel.

## How it works

"Guard" here is a Laravel authentication concept — it decides *who* is logged in. AzGuard's
isolation unit is the **panel** — it decides *which permission namespace* (`app.*`, `admin.*`)
applies. The two are not bound together in code: a panel is just a permission namespace, and
which auth guard fronts a given area of your app is entirely up to your own routing/middleware
setup.

In practice you typically pair one auth guard with one panel per area of your application:

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
