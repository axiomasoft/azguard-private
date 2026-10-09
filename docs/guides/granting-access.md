# Granting access

Grants are written through the panel's **writer**, usually `DatabaseSource`. Every change method returns a
`ChangeResult` and goes through one change pipeline:

1. validation;
2. the `changing` pipes;
3. a transaction;
4. a bump of the panel version and of each affected subject's revision, which invalidates the cached grants
   of those subjects only;
5. events after commit.

## Roles and permissions

```php
$user->grantRole(EditorRole::class);
$user->grantRole('editor', on: $team);                           // in an assignment scope
$user->grantRole('editor', until: now()->addMonth());            // expires
$user->revokeRole('editor');                                     // tenant-wide grant only
$user->revokeRole('editor', on: AnyAssignmentScope::all());      // in every scope of the tenant
$user->syncRoles(['editor', 'author']);                          // exactly these, tenant-wide

$user->grantPermission(PostPermission::Delete, until: now()->addHour());
$user->grantPermission('posts.*');                               // a pattern
$user->revokePermission(PostPermission::Delete);
$user->syncPermissions([PostPermission::View]);
```

- **The panel.** It is chosen as for checks. Use `$user->guard('admin')->grantRole(...)` or
  `AzGuard::panel('admin')->for($user)->grantRole(...)` to name it.
- **Scope of revoke and sync.** `on:` addresses one scope. `revoke*()` and `sync*()` never touch other
  scopes unless you pass `AnyAssignmentScope::all()` to revoke.
- **Writer.** A panel without a writer throws `PanelNotWritableException`. An example is a panel with only
  `RelationSource`.

## The result

```php
$result = $user->grantRole('editor');
$result->status;            // ChangeStatus::Applied | ChangeStatus::Unchanged
$result->applied();         // false when the same grant already existed
$result->removedGrantIds(); // ids removed by a revoke or sync
$result->state;             // the new panel state token
```

Granting the same role again in the same panel, tenant, scope and origin is `Unchanged`. It publishes no event.
A grant with a new expiry or new fields updates the stored row.

## The actor

Each change records who made it:

```php
AzGuard::actingAs($admin, fn () => $user->grantRole('editor'));             // a model
AzGuard::actingAs('import: crm nightly', fn () => $user->grantRole('editor')); // the system, with a reason
```

Without `actingAs()`, the actor is the authenticated user, or the system in the console.

## Custom grant fields

To store extra columns on grants, such as a reason or a department:

```bash
php artisan azguard:make:models Admin   # AdminRoleGrant, AdminPermissionGrant and a migration
```

```php
final class AdminRoleGrant extends RoleGrant
{
    public static function azguardFields(): array
    {
        return [Field::string('reason')];
    }
}
```

Then:

- add the column to the generated migration (`$table->string('reason')->nullable()`) and migrate;
- attach the model to the source with `DatabaseSource::make()->models(roleGrant: AdminRoleGrant::class)`.

```php
$user->grantRole('editor', fields: ['reason' => 'Covers vacation']);
$user->guard('admin')->roleGrants()->first()->reason;   // 'Covers vacation'
```

An unknown field throws `InvalidChangeFieldsException`. Fields can also qualify grants in decisions (grant
conditions) and appear in the Filament editors.

## Command line

```bash
php artisan azguard:roles:grant 1 editor --until=2026-12-31T23:59:59Z --on=team:3
php artisan azguard:roles:revoke 1 editor
php artisan azguard:permissions:grant user:1 posts.delete --until=2026-10-10T00:00:00Z
php artisan azguard:permissions:revoke user:1 posts.delete
php artisan azguard:grants:list 1            # stored grants of a subject
php artisan azguard:permissions:show 1       # effective roles and permissions
```

The subject is `type:id`, or just the id when the panel has one subject model. Panels with tenants need
`--tenant=type:id`.

## Expiry and pruning

An expired grant stops applying at its expiry instant, even from a cache. The rows are removed by
`azguard:grants:prune`, which also publishes `GrantExpired`. The package schedules it daily
(`azguard.schedule.prune_expired`). Set the value to `null` to schedule it yourself.

```bash
php artisan azguard:grants:prune --dry-run
```

## Events

Changes publish Laravel events after commit: `RoleGranted`, `RoleRevoked`, `RoleGrantUpdated`,
`PermissionGranted`, `PermissionRevoked`, `PermissionGrantUpdated`, `GrantExpired`, `PermissionCreated`,
`PermissionUpdated`, `PermissionDeleted` and `PanelStateTouched`. See [Events](/reference/events).

## Changing pipes

A panel can intercept every change, for example to require a reason or to forbid self-promotion:

```php
->changing([RequireReason::class])   // handle(Change $change, Closure $next)
```

Throwing `ChangeCancelledException` from a pipe rolls the whole change back. See
[Hooks and plugins](/advanced/hooks-and-plugins).

## Bulk and UI operations

`AzGuard::panel('admin')->grants()` returns a `GrantManager` scoped to the panel and tenant. It has:

- `page(GrantFilter)` for typed filters and pagination;
- `find($id)`;
- `update($id, GrantDetails, $expectedFingerprint)` to change an expiry or fields;
- `revokeMany($ids)`.

The Filament editors are built on it.
