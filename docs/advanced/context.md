# Contextual Permissions (azguard/context)

The `azguard/context` package is an opt-in extension for multi-workspace /
multi-site scenarios. A user can hold **different permissions in different
contexts** (workspace, project, organisation, etc.) on the same panel.

## Installation

```bash
composer require axioma-studio/azguard-context
php artisan vendor:publish --tag=azguard-context-migrations
php artisan migrate
```

## Concepts

| Term | Description |
|---|---|
| **AuthorizationContext** | Value object: `panelId` + `contextType` + `contextId` |
| **AuthorizationContextManager** | Singleton: holds the active per-panel context for the duration of a request |
| **ResolvesContext** | Resolver interface — extracts the context from a `Request` |
| **MergeStrategy** | Strategy for merging global and contextual permissions |
| **ContextualRoleGrantSource** | A `GrantSource` with priority 95, reads the `az_guard_context_roles` table |

## Quick start

### 1. Create a resolver

```php
use AzGuard\Context\Contracts\ResolvesContext;
use AzGuard\Context\AuthorizationContext;
use Illuminate\Http\Request;

final class WorkspaceContextResolver implements ResolvesContext
{
    public function resolve(Request $request): ?AuthorizationContext
    {
        $id = $request->route('workspace');

        return $id
            ? new AuthorizationContext('app', 'workspace', $id)
            : null;
    }

    public function panelId(): string
    {
        return 'app';
    }
}
```

### 2. Register it in the config

```php
// config/az-guard-context.php
'resolvers' => [
    App\Guards\WorkspaceContextResolver::class,
],
```

### 3. Apply the middleware to a route

The `azguard.context` alias is registered automatically in
`AzGuardContextServiceProvider::boot()` — no manual wiring in
`bootstrap/app.php` is needed.

```php
// routes/web.php
Route::middleware(['auth', 'azguard.context'])
    ->group(function () {
        Route::get('/workspaces/{workspace}/posts', PostController::class);
    });
```

From this point, `$user->hasPermission('app.posts.edit')` automatically
takes the user's permissions in the current workspace into account.

## Checking permissions

### Global (no context)

```php
$user->hasPermission('app.posts.edit');
```

### One-off contextual check

Does not change the global `AuthorizationContextManager`:

```php
use AzGuard\Context\AuthorizationContext;

// Via the convenience alias
$user->hasPermissionIn('workspace', $workspaceId, 'app.posts.edit');

// Via the primary method with a PermissionContext object
$user->hasPermission('app.posts.edit', 'app', new AuthorizationContext(
    panelId: 'app',
    contextType: 'workspace',
    contextId: $workspaceId,
));
```

### Silent version (for Blade / UI)

```php
use AzGuard\Context\AuthorizationContext;

$user->checkPermission('app.posts.edit', 'app', new AuthorizationContext(
    panelId: 'app',
    contextType: 'workspace',
    contextId: $workspaceId,
));
```

### Blade directive

```blade
@azcan('app.posts.edit')
    {{-- permission from the current context (if the middleware is applied) --}}
@endazcan
```

## Issuing contextual grants

Grants are stored in the `az_guard_context_roles` table. Write them via
`ContextGrantBuilder` (a fluent write-API, the counterpart of
`AzGuard\Grants\GrantBuilder` for panel-wide direct grants) or via the CLI:

```php
use AzGuard\Context\ContextGrantBuilder;

(new ContextGrantBuilder($user))
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->grant('app.posts.edit');

// Revoke a specific permission
(new ContextGrantBuilder($user))
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->revoke('app.posts.edit');

// Revoke every permission the user holds in this context+panel
(new ContextGrantBuilder($user))
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->revokeAll();
```

Wildcard (`*`) grants full access within the context:

```php
(new ContextGrantBuilder($user))
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->grant('*');
```

### CLI

```bash
# issue a contextual grant
php artisan guard:context:grant 42 app.posts.edit app workspace 7

# revoke a specific permission
php artisan guard:context:revoke 42 app.posts.edit app workspace 7

# revoke every permission the user holds in this context+panel
# (the permission argument is required but ignored with --all)
php artisan guard:context:revoke 42 ignored app workspace 7 --all
```

## Merge strategies

Configured in `config/az-guard-context.php`:

```php
'merge_strategy' => \AzGuard\Context\Strategies\GlobalPlusContextStrategy::class,
```

| Class | Behaviour |
|---|---|
| `GlobalPlusContextStrategy` | global ∪ context **(default)** |
| `ContextOnlyStrategy` | context only, global is ignored |
| `DenyWithoutContextStrategy` | empty set without a context; with a context — global ∪ context |

You can implement your own strategy:

```php
use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Registry\Values\PermissionSet;

final class MyStrategy implements MergeStrategy
{
    public function merge(PermissionSet $global, ?PermissionSet $context): PermissionSet
    {
        // your logic
    }
}
```

## GrantSource priorities

| Source | Priority |
|---|---|
| ClassRoleGrantSource | 100 |
| **ContextualRoleGrantSource** | **95** |
| DatabaseRoleGrantSource | 90 |
| DirectGrantSource | 80 |

All sources are merged in `EffectivePermissionResolver` — contextual
permissions do not "override" a class role, they extend the set.

## Backward compatibility

- The package is **opt-in**: if it is not installed, `HasAzGuard` behaves exactly as before.
- `hasPermissionIn()` returns `false` if the package is not installed.
- `hasPermission(..., $context)` falls back to a global check if the package is not installed.
