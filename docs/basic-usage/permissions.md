# Permissions

Permissions in AzGuard are **PHP enum cases**, not database records. They live in your codebase, are reviewed in PRs, and are always in sync with your application logic.

## Naming convention

Every permission key follows the pattern `{panel}.{resource}.{action}`:

```
app.documents.view
app.documents.create
admin.users.delete
api.reports.export
```

The panel prefix (`app.`) is added automatically by AzGuard based on the panel the enum is registered in. Inside your enum you only declare `documents.view`.

## Defining permissions

```php
use AzGuard\Attributes\RoleOnly;

enum DocumentsPermission: string
{
    case View   = 'documents.view';

    case Create = 'documents.create';

    case Edit   = 'documents.edit';

    // Assignable to roles, but excluded from Gate / frontend ability export
    #[RoleOnly]
    case Delete = 'documents.delete';
}
```

The enum is a plain backed enum — it implements no interface. Register it on a panel with `Panel::permissionEnums([DocumentsPermission::class])`; the panel prefixes each value.

## Attributes

| Attribute | Effect |
|---|---|
| `#[RoleOnly]` | The case is a valid permission for roles, but is omitted from the generated frontend abilities / Gate-facing surface |
| _(none)_ | Normal permission — resolvable everywhere |

## Generate via Artisan

```bash
php artisan make:guard-permission {Panel} {Domain} {Case?}

php artisan make:guard-permission App Documents
php artisan make:guard-permission Admin Users
```

The command creates (or adds a case to) `{Domain}Permission` under the panel's `Permissions/` directory. Pass an optional case name to append a single case; omit it to scaffold the enum. Register the enum in your panel provider via `permissionEnums([...])`.

## Checking permissions

AzGuard provides several methods for checking permissions. Use the right one for the context:

```php
// ── On the User model (via HasAzGuard) ────────────────────────────────────

// Returns true/false — the everyday check
$user->hasPermission(DocumentsPermission::View);    // enum — scoped to its panel
$user->hasPermission('app.documents.view');         // full string key also works

// Check several yourself
$hasAny = $user->hasPermission(DocumentsPermission::Edit)
    || $user->hasPermission(DocumentsPermission::Delete);

$hasAll = $user->hasPermission(DocumentsPermission::View)
    && $user->hasPermission(DocumentsPermission::Edit);

// All resolved keys for a panel (collection of strings)
$user->permissions('app');     // Collection<int, string>

// Silent check — never throws, returns false on missing or expired grants
$user->checkPermission(DocumentsPermission::View);
```

```php
// ── Via Laravel Gate (registered automatically via Gate::before) ──────────

Gate::allows('app.documents.view');          // bool
Gate::check('app.documents.view');           // alias
$this->authorize('app.documents.view');      // throws AuthorizationException on deny
Gate::authorize('app.documents.view');       // same, usable outside controllers

// With a model (routes through your Policy)
Gate::allows('app.documents.edit', $document);
$this->authorize('app.documents.edit', $document);
```

```php
// ── In Blade ─────────────────────────────────────────────────────────────

@can('app.documents.edit')
    <a href="{{ route('documents.edit', $doc) }}">Edit</a>
@endcan

@cannot('app.documents.delete')
    <p>Read-only access.</p>
@endcannot

@canany(['app.documents.edit', 'app.documents.delete'])
    <div class="actions">...</div>
@endcanany
```

::: tip Checking several permissions
There is no `hasAny`/`hasAll` helper — combine `hasPermission()` calls with `&&` / `||`. Each call short-circuits, so order the cheapest or most-likely-to-fail check first.

```php
// ✅ User must have BOTH
if ($user->hasPermission(ReportsPermission::View) && $user->hasPermission(ReportsPermission::Export)) {
    return $this->buildReport();
}

// ✅ At least one of these
if ($user->hasPermission(DocumentsPermission::Edit) || $user->hasPermission(DocumentsPermission::Delete)) {
    // show edit actions toolbar
}
```
:::

## Inspecting what a user has

```php
// All resolved permission keys for a panel (roles + direct grants combined)
$user->permissions('app');            // Collection<int, string>

// The underlying PermissionSet (supports wildcard matching)
$user->permissionSet('app');          // PermissionSet

// Direct grants only (requires HasDirectGrants)
$user->grants('app');                 // Collection<DirectGrant>

// Check containment
$user->permissions('app')->contains('app.documents.view');
```

## All permission-check methods at a glance

| Method | Returns | Throws? | Gate? |
|---|---|---|---|
| `hasPermission($perm)` | `bool` | No | No |
| `checkPermission($perm)` | `bool` | No | No |
| `permissions($panelId)` | `Collection<int,string>` | No | No |
| `Gate::allows($key)` | `bool` | No | Yes |
| `$this->authorize($key)` | `void` | Yes (403) | Yes |
| `Gate::authorize($key)` | `void` | Yes (403) | Yes |

## Assigning permissions to a role

In static roles, permissions are declared directly in code as **enum cases** — the panel scopes each case automatically (no `"app."` prefix):

```php
use App\Guards\App\Documents\Permissions\DocumentsPermission;

public function permissions(): array
{
    return [
        DocumentsPermission::View,
        DocumentsPermission::Create,
        DocumentsPermission::Edit,
    ];
}
```

Each enum must be registered on its panel via `->permissionEnums([...])`. After changing role classes, run `php artisan guard:sync-roles` to mirror them into the DB before assigning.

For DB-backed roles, manage permission keys with the `guard:role-permissions` command:

```bash
# Add a key
php artisan guard:role-permissions add editor app.documents.view --panel=app

# Replace the full list
php artisan guard:role-permissions sync editor --panel=app --keys=app.documents.view,app.documents.edit

# Remove one
php artisan guard:role-permissions remove editor app.documents.create --panel=app

# List
php artisan guard:role-permissions list editor --panel=app
```

## Frontend abilities

To expose a resource's resolved permissions to the frontend, generate an **Abilities DTO** for a domain:

```bash
php artisan make:guard-abilities App Documents
```

This creates `{Domain}Abilities` (extending `AzGuard\Abilities\AbilitiesDto`) under the panel's `Abilities/` directory. The DTO exposes boolean flags (`viewAny`, `view`, `create`, `update`, `delete`) mapped to the panel-resolved permission keys, evaluated against `Gate`. Serialize it into your Inertia/JSON props with `toArray()`:

```php
$abilities = new DocumentsAbilities(/* ...resolved flags... */);

return inertia('Documents/Index', [
    'can' => $abilities->toArray(),  // ['viewAny' => true, 'view' => true, ...]
]);
```

See [Frontend Abilities](/basic-usage/abilities-frontend) for Inertia / Vue / React integration.

## Listing all permissions

```bash
# All permissions across all panels
php artisan guard:list-permissions

# Filter by panel (positional argument)
php artisan guard:list-permissions app
```

## Gotchas

**Permission keys are panel-scoped.** `documents.view` and `app.documents.view` are the same permission if the enum is registered under the `app` panel. Using the string form requires the full key including the panel prefix.

**`#[RoleOnly]` permissions are excluded from the Gate-facing surface.** They are meant to be assigned to roles and checked via `$user->hasPermission(...)`; `guard:doctor` will not flag them for a missing policy method, and they are omitted from generated abilities.

**Don't mix string keys and enum cases carelessly.** Always use enum cases in PHP code (`DocumentsPermission::View`). Reserve string keys for places where enums aren't available (config files, migrations, Artisan commands).

## Best practices

- **One enum per resource.** `DocumentsPermission`, `UsersPermission`, `ReportsPermission` — not one giant `AppPermission` enum.
- **CRUD as the default set.** `view`, `create`, `edit`, `delete`. Add extras as needed: `export`, `publish`, `approve`.
- **Mark internal-only cases with `#[RoleOnly]`** when they should be assignable to roles but kept out of the generated frontend abilities.
- **Never hardcode string keys in PHP.** Always reference enum cases.
- **Keep enum cases descriptive but terse.** `case Export` is fine; `case ExportToCsvForExternalTeams` is not.
