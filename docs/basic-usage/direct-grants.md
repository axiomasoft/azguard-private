# Direct Grants

::: warning Prefer roles over direct grants
Direct Grants are an **exception** mechanism, not a primary access-control pattern. The correct default is to assign permissions to roles, and assign roles to users. Use direct grants only when a specific user needs a temporary or one-off permission that doesn't warrant a new role.

See [Roles vs Permissions](/best-practices/best-practices#roles-vs-permissions) for the full guidance.
:::

Direct Grants let you assign a permission **directly to a user** without creating a role for it. Typical use cases:

- Temporary access (beta feature, limited-time export)
- One-off override for a specific user
- Feature flags scoped to individual accounts

## When to use direct grants

| Situation | Recommendation |
|---|---|
| One user needs a permission no one else has | ✅ Direct grant |
| A user needs temporary access (expires in N hours) | ✅ Direct grant with TTL |
| Multiple users need the same permission | ❌ Create a role instead |
| Permission is part of a user's everyday job | ❌ Assign a role instead |
| You find yourself granting the same permission to 5+ users | ❌ Time to create a role |

## Connecting the trait

Add `HasDirectGrants` to your User model alongside `HasAzGuard`:

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Concerns\HasDirectGrants;

class User extends Authenticatable
{
    use HasAzGuard, HasDirectGrants;
}
```

::: tip
`HasDirectGrants` extends `hasPermission()`: it now checks roles **and** direct grants. No other code changes needed.
:::

## Granting a permission

### Fluent API

```php
use AzGuard\Facades\AzGuard;

// Permanent
AzGuard::forUser($user)
    ->on('app')
    ->grant(DocumentsPermission::Export);

// With a 1-hour TTL (seconds)
AzGuard::forUser($user)
    ->on('app')
    ->ttl(3600)
    ->grant(DocumentsPermission::Export);

// With an absolute expiry timestamp
AzGuard::forUser($user)
    ->on('app')
    ->until(now()->addDays(30))
    ->grant(DocumentsPermission::Export);

// Directly on the model (pass an optional expiry)
$user->grant(DocumentsPermission::Export, 'app');
$user->grant(DocumentsPermission::Export, 'app', now()->addHour());
```

::: info Idempotent
Calling `grant()` on an already-granted permission updates `expires_at` without creating a duplicate. Safe to call multiple times.
:::

::: tip Immutable builder
Every scope setter (`on()` / `ttl()` / `until()`) returns a **new** builder instance, so branches from one builder never see each other's state — you can safely reuse a base builder for several grants. In a multi-workspace app the same root also extends into a context: `AzGuard::forUser($user)->on('app')->inContext('workspace', 42)->grant(...)` (see [Contexts](../advanced/context.md)).
:::

### Artisan

```bash
# Permanent
php artisan guard:grant {user-id} {permission} {panel}

# With TTL (seconds)
php artisan guard:grant 42 app.documents.export app --ttl=3600

# Different model
php artisan guard:grant 7 admin.reports.view admin --model=App\\Models\\Admin
```

## Revoking a grant

```php
// Single permission — enum
AzGuard::forUser($user)->on('app')->revoke(DocumentsPermission::Export);

// All grants for a panel
AzGuard::forUser($user)->on('app')->revokeAll();
```

```bash
# Artisan
php artisan guard:revoke-grant 42 app.documents.export app

# Revoke every grant for the panel (permission arg is ignored with --all)
php artisan guard:revoke-grant 42 - app --all --force
```

## Checking a grant

```php
// On the User model — enum or string accepted, enum preferred
$user->hasGrant(DocumentsPermission::Export);
$user->hasGrant(DocumentsPermission::Export, 'app');

// Via Laravel Gate — pass the full string key (optionally [key, panel])
Gate::allows('direct-grant', 'app.documents.export');
Gate::allows('direct-grant', ['app.documents.export', 'app']);

// List grants for a panel
$grants = AzGuard::forUser($user)->on('app')->grants();
```

## Blade

```blade
{{-- Pass the full panel-prefixed key --}}
@azdirect('app.documents.export')
    <button>Export</button>
@endazdirect

{{-- Explicit panel as the second argument --}}
@azdirect('app.documents.export', 'app')
    <button>Export</button>
@endazdirect
```

## Route middleware

```php
// azguard.grant:{permission},{panel} — full key required by middleware
Route::get('/export', ExportController::class)
    ->middleware('azguard.grant:app.documents.export,app');

// Panel inferred from the current AzGuard panel if omitted
Route::get('/export', ExportController::class)
    ->middleware('azguard.grant:app.documents.export');
```

| Situation | HTTP status |
|---|---|
| Not authenticated | 401 |
| Grant missing or expired | 403 |
| Grant active | passes through |

## TTL and expiry

A grant with `expires_at < now()` is treated as inactive in all checks. Expired records are cleaned up by the scheduler:

```php
// bootstrap/app.php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('guard:prune-grants')->daily();
})
```

```bash
php artisan guard:prune-grants
php artisan guard:prune-grants --panel=app
```

## Events

| Event | When dispatched |
|---|---|
| `GrantGiven` | After every `grant()` call |
| `GrantRevoked` | After every `revoke()` / `revokeAll()` call (`permissionKey` is `*` for `revokeAll()`) |

```php
use AzGuard\Events\GrantGiven;
use AzGuard\Events\GrantRevoked;

Event::listen(GrantGiven::class, function (GrantGiven $event): void {
    Log::info("Grant [{$event->permissionKey}] issued to user #{$event->user->getAuthIdentifier()}");
});

Event::listen(GrantRevoked::class, function (GrantRevoked $event): void {
    // e.g., invalidate API cache
});
```

## Quick reference

| Method | Code |
|---|---|
| Fluent grant | `AzGuard::forUser($u)->on('app')->ttl(3600)->grant(DocumentsPermission::Export)` |
| Absolute expiry | `AzGuard::forUser($u)->on('app')->until($date)->grant(DocumentsPermission::Export)` |
| Artisan grant | `php artisan guard:grant {id} {perm} {panel}` |
| Revoke | `AzGuard::forUser($u)->on('app')->revoke(DocumentsPermission::Export)` |
| Check (model) | `$user->hasGrant(DocumentsPermission::Export, 'app')` |
| Check (Gate) | `Gate::allows('direct-grant', 'app.documents.export')` |
| Blade | `@azdirect('app.documents.export') ... @endazdirect` |
| Middleware | `->middleware('azguard.grant:app.documents.export,app')` |
