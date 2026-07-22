# Entity-Scoped Roles

Entity-scoped roles let you assign a role to a user **for a specific model instance**. A user can be `editor` on Project A but have no role on Project B.

This stacks on top of global roles — it does not replace them.

::: tip
Looking for a request-scoped "current workspace/tenant" switch instead of a persisted
per-record role? See [Context or scope?](/advanced/context#context-or-scope) in the
`azguard/context` docs.
:::

## Setup

Add `HasScopedRoles` to any Eloquent model that should support scoped role assignment:

```php
use AzGuard\Concerns\HasScopedRoles;

class Project extends Model
{
    use HasScopedRoles;
}
```

Your `User` model must already use `HasAzGuard`.

## Assigning & removing scoped roles

```php
// Assign
$user->assignScopedRole(EditorRole::class, $project);

// Remove
$user->removeScopedRole(EditorRole::class, $project);

// Check
$user->hasScopedRole(EditorRole::class, $project); // bool
```

## Checking a scoped permission

`hasScopedPermission()` resolves permissions in this order:

1. **Wildcard** — if any global role has `['*']`, return `true`.
2. **Global roles** — permissions from `assignRole()` checked first.
3. **Scoped roles** — permissions from `assignScopedRole($entity)` checked for the given entity.

```php
if ($user->hasScopedPermission(DocumentsPermission::Edit, $project)) {
    // user can edit this specific project
}
```

The Gate integration uses scoped permissions automatically when an entity is passed as the second argument:

```php
Gate::allows('app.documents.edit', $project); // uses scoped resolution
```

## Use cases

| Scenario | Scoped role |
|---|---|
| Multi-tenant projects | `editor` scoped to `Project` |
| Team management | `team-admin` scoped to `Team` |
| Document review | `reviewer` scoped to `Document` |
| Resource ownership | `owner` scoped to any Eloquent model |

## Cache

Scoped permission cache is cleared automatically on `assignScopedRole()` and `removeScopedRole()`. For manual flush:

```bash
php artisan guard:cache-reset
```
