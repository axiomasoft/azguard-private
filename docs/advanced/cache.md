# Cache

AzGuard caches resolved permission sets to avoid repeated database queries within a single request. The cache is **request-scoped by default** — making it safe for Octane and Kubernetes without any configuration.

## How caching works

```
Request comes in
  → User authenticated
  → First hasPermission() / Gate::allows() call
      → EffectivePermissionResolver queries all GrantSources (roles + direct grants)
      → Result stored in PermissionResolverCache (in-memory, request-scoped)
  → Subsequent checks on the same request
      → Cache hit — zero DB queries
```

The in-memory cache is **never shared** between requests. Under Octane, each worker resolves permissions independently per request.

## Absolute grant deadlines

`PermissionSet::validUntil()` is an optional absolute UTC instant. Built-in direct and context grants set it to the nearest active `expires_at`. On every request-cache and durable-cache hit, AzGuard treats `now >= validUntil` as a miss, refreshes once, and does not store a result that is already expired. The durable payload is `{version, keys, valid_until}`. Backend TTL is the earlier of `expiration_time` and that deadline; `expiration_time => null` does not cancel a deadline. A custom source or layer that leaves `validUntil()` null still follows only the configured TTL.

## Manual cache flush

If you modify roles or grants within the same request (e.g., in a test or admin action), flush the cache to get fresh results:

```php
$user->assignRole('editor');
$user->flushPermissions();  // clears in-memory cache for this user

$user->hasPermission(DocumentsPermission::View);  // re-resolves from DB
```

## Cache in tests

Always call `flushPermissions()` between state changes in a single test:

```php
public function test_role_change_takes_effect(): void
{
    $user = User::factory()->create();
    $user->assignRole('viewer');

    $this->assertFalse($user->hasPermission(DocumentsPermission::Delete));

    // Upgrade role
    $user->syncRoles(['editor']);
    $user->flushPermissions();  // required — clears the in-memory cache

    $this->assertTrue($user->hasPermission(DocumentsPermission::Edit));
}
```

## Redis / persistent cache (optional)

For high-traffic applications, enable cross-request caching by pointing `cache.store`
at a persistent store (the default `'array'` store is request-scoped):

```php
// config/az-guard.php
'cache' => [
    'store'           => 'redis',
    'expiration_time' => 300,
    'generation'      => 1,
],
```

With a persistent store, resolved `PermissionSet` objects are serialized into the cache store. The cache key includes the typed subject identity, panel, permission-state revision, and `cache.generation`. Official mutations bump the revision in the same DB transaction, so a committed revoke cannot stay hidden behind a stale entry. Changing `generation` opens a new namespace; old keys expire under TTL. `guard:cache-reset` advances the revision and clears local request state — it does **not** flush the configured store.

::: warning Always flush after unofficial bulk writes
Official `assignRole` / `grant` / role-permission sync paths bump the revision themselves. After raw SQL or other bulk writes, run `php artisan guard:cache-reset --force` (or bump revision) so the next check cannot reuse a previous namespace.
:::

## Cache key structure

```
azg:v2:perm:{digest(subject, panel, discriminator, epoch, revision, generation)}
```

The per-subject epoch remains a local optimization. The global permission-state revision and `cache.generation` are the correctness fence: a committed mutation or `guard:cache-reset` makes previous keys miss, and old entries expire under TTL without a store-wide flush.

## Redis strategy recommendations

| Scenario | Recommended `expiration_time` | Notes |
|---|---|---|
| Low-traffic app | Request-scoped only (`store: 'array'`) | No overhead, simplest setup |
| Standard SaaS | 300s (5 min) | Good balance, minimal staleness |
| High-traffic read-heavy | 600–1800s | Accept slightly longer flush delay |
| Admin / security-critical | 60s or `store: 'array'` | Faster invalidation on role changes |

## Cache invalidation via events

Auto-invalidate the cache when a user's status changes:

```php
// app/Observers/UserObserver.php
class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->isDirty('status') || $user->isDirty('is_active')) {
            $user->flushPermissions();
        }
    }
}
```

Or hook into AzGuard's own events:

```php
use AzGuard\Events\RoleAttached;
use AzGuard\Events\RoleDetached;
use AzGuard\Events\GrantGiven;

// AzGuard automatically flushes the cache on these events,
// but you can listen to chain additional side effects:
Event::listen(RoleAttached::class, function (RoleAttached $event): void {
    Log::info("Role [{$event->role->name}] assigned to model #{$event->model->getKey()}");
});
```

## Octane notes

AzGuard's in-memory cache uses a plain PHP array scoped to the current request — no static properties or container singletons that survive between requests. It is safe to use with:

- Laravel Octane (Swoole, RoadRunner)
- Kubernetes with multiple replicas
- Queue workers (each job is its own request cycle)

::: tip
Keep the cache store on `'array'` in tests to avoid cross-test contamination. The request-scoped cache is automatically cleared between test cases by `RefreshDatabase`.
:::

## Performance tips

- **Do not call `flushPermissions()` in middleware** unless necessary — it defeats the request cache and re-queries on every check.
- **Eager-load roles** when building user lists: `User::with('roles')->paginate()`. This prevents N+1 during the first permission resolution.
- **With persistent cache**, set an `expiration_time` that reflects how frequently roles change in your app. 5 minutes is a safe starting point.
