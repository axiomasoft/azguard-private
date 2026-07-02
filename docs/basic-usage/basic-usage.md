# Basic Usage

This page walks you through the full lifecycle: defining roles and permissions, assigning them to users, checking access, and querying users by role or permission.

## Add the trait

Add `HasAzGuard` to every model that needs role/permission checks:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use AzGuard\Concerns\HasAzGuard;

class User extends Authenticatable
{
    use HasAzGuard;
}
```

::: tip
See [Prerequisites](/introduction/prerequisites) for important constraints — especially the list of reserved property names.
:::

## Define a permission

Permissions in AzGuard are PHP enum cases, not strings in a database. Define one enum per resource:

```php
// app/Guards/App/Documents/Permissions/DocumentsPermission.php
enum DocumentsPermission: string
{
    case View   = 'documents.view';

    case Create = 'documents.create';

    case Edit   = 'documents.edit';

    case Delete = 'documents.delete';
}
```

See [Permissions](/basic-usage/permissions) for the full attribute reference.

## Define a role

Roles are PHP classes that declare which permissions they grant. Return **enum cases**, not strings — the panel scopes each case automatically (no `"app."` prefix):

```php
// app/Guards/App/Roles/EditorRole.php
use AzGuard\Roles\BaseRole;
use App\Guards\App\Documents\Permissions\DocumentsPermission;

class EditorRole extends BaseRole
{
    public function getLevel(): int { return 10; }

    public function permissions(): array
    {
        return [
            DocumentsPermission::View,
            DocumentsPermission::Create,
            DocumentsPermission::Edit,
        ];
    }
}
```

Each enum must be registered on its panel via `->permissionEnums([...])`. After declaring or changing role classes, run `php artisan guard:sync-roles` to mirror them into the DB before assigning.

See [Roles](/basic-usage/roles) for dynamic (DB-backed) roles and level-based hierarchy.

## Assign / remove / sync roles

```php
// Assign one role — by class (preferred: unambiguous and refactor-safe)
$user->assignRole(EditorRole::class);
$user->assignRole('editor');                     // by name also works

// Assign several at once (variadic)
$user->assignRole(EditorRole::class, ViewerRole::class);

// Remove one role
$user->removeRole(EditorRole::class);
$user->removeRole('editor');                     // by name also works

// Sync — replaces ALL current roles with the given list
$user->syncRoles([EditorRole::class, ViewerRole::class]);
$user->syncRoles(['editor', 'viewer']);          // by name also works

// Remove all roles
$user->syncRoles([]);
```

## Check roles

```php
$user->hasRole(EditorRole::class);                 // bool — by class (preferred)
$user->hasRole('editor');                          // by name also works
$user->getRoleNames();                             // Collection<string>
$user->roles();                                    // the roles() relation
```

## Check permissions

```php
// Via the trait — an enum case is scoped to the panel automatically
$user->hasPermission(DocumentsPermission::View);
// A string must be the full panel-prefixed key
$user->hasPermission('app.documents.view');
// Silent check — never throws (safe in Blade)
$user->checkPermission(DocumentsPermission::View);

// Via Laravel Gate — pass the full permission key
use Illuminate\Support\Facades\Gate;

Gate::allows('app.documents.view');

// In a controller action — throws 403 on failure
$this->authorize('app.documents.view');

// Middleware on a route
Route::get('/documents', DocumentController::class)
    ->middleware('can:app.documents.view');
```

::: tip Enum cases vs. string keys
An enum case (`DocumentsPermission::View`) is scoped to its panel automatically and is refactor-safe. Strings must be the full panel-prefixed key (`'app.documents.view'`); a typo fails silently, so prefer enum cases in PHP and reserve strings for Gate/Blade/routes.
:::

::: tip Always check permissions, not roles
Prefer `$user->hasPermission(...)` or `Gate::allows(...)` over `$user->hasRole(...)` for access control. Roles change over time; permissions express intent and are stable.
:::

## Inspect what a user has

```php
// All resolved permission keys for the current (or given) panel
$user->permissions();                // Collection<int, string>
$user->permissions('app');           // for an explicit panel

// The underlying PermissionSet (supports wildcards)
$user->permissionSet('app');         // PermissionSet

// Role names
$user->getRoleNames();               // Collection<string>

// Direct grants for a panel (requires HasDirectGrants)
$user->grants('app');                // Collection<DirectGrant>
$user->hasGrant(DocumentsPermission::View, 'app');  // bool
```

## Query users by role

`HasAzGuard` exposes a `roles()` relation. Query it with standard Eloquent:

```php
// Users that have a specific role
User::whereHas('roles', fn ($q) => $q->where('name', 'editor'))->get();

// Users that have any of these roles
User::whereHas('roles', fn ($q) => $q->whereIn('name', ['editor', 'admin']))->get();

// Users that do NOT have a specific role
User::whereDoesntHave('roles', fn ($q) => $q->where('name', 'editor'))->get();
```

These can be chained with other Eloquent calls:

```php
User::whereHas('roles', fn ($q) => $q->where('name', 'editor'))
    ->where('active', true)
    ->orderBy('name')
    ->paginate();
```

## Useful Eloquent patterns

```php
// Eager-load roles for a user list (avoids N+1)
User::with('roles')->paginate();

// Users with no roles at all
User::doesntHave('roles')->get();

// Count users per role, grouped
User::with('roles')
    ->get()
    ->flatMap->roles
    ->countBy('name');
// => ['editor' => 12, 'admin' => 3, 'viewer' => 47]
```

## Gate check in Blade

In Blade templates, pass pre-resolved booleans from the controller (preferred) or use the enum `->value` property:

```php
// In the controller — resolve once, pass as data
public function edit(Document $document): Response
{
    return view('documents.edit', [
        'document' => $document,
        'can' => [
            'edit'   => Gate::allows('app.documents.edit',   $document),
            'delete' => Gate::allows('app.documents.delete', $document),
        ],
    ]);
}
```

```blade
{{-- ✅ Option 1: pre-resolved boolean from the controller (preferred) --}}
@if($can['edit'])
    <a href="{{ route('documents.edit', $document) }}">Edit</a>
@endif

{{-- ✅ Option 2: full panel-prefixed key string --}}
@can('app.documents.edit')
    <a href="{{ route('documents.edit', $document) }}">Edit</a>
@endcan

@cannot('app.documents.delete')
    <span class="text-muted">No delete access</span>
@endcannot

@canany(['app.documents.create', 'app.documents.edit'])
    <div class="editor-toolbar">...</div>
@endcanany
```

::: tip Strings in Blade
Blade directives take the full panel-prefixed permission key (`'app.documents.edit'`). Pass pre-resolved booleans from the controller whenever possible to keep templates simple.
:::

See [Blade Directives](/basic-usage/blade-directives) for role checks and custom directives.

::: tip Next steps
- [Permissions](/basic-usage/permissions) — defining enums, attributes, TypeScript export
- [Roles](/basic-usage/roles) — static vs dynamic roles, levels, artisan commands
- [Direct Grants](/basic-usage/direct-grants) — per-user permissions without roles
- [HTTP Access](/basic-usage/http-access) — middleware, route groups, policies
:::
