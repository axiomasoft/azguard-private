# Policies & Gates

AzGuard sits on top of Laravel's native Gate. You write standard Laravel policies; AzGuard auto-registers them and adds the panel-aware permission layer on top.

## How registration works

When the panel provider boots:

1. It scans `**/Policies/**/*Policy.php` under your Guards directory.
2. `PolicyAttributeRegistrar` reads `#[GateAbility]` attributes on policy methods.
3. For each method, it calls `Gate::define(resolvedAbility, [Policy::class, 'method'])`.
4. If `#[GuardPolicy(model: Foo::class)]` is present (or autodiscovered), it also calls `Gate::policy($model, $policy)`.

## Writing a policy

```php
namespace App\Guards\App\Documents\Policies;

use AzGuard\Attributes\GateAbility;
use AzGuard\Attributes\GuardPolicy;
use App\Models\Documents\Document;
use App\Models\User;
use App\Guards\App\Documents\Permissions\DocumentsPermission;

#[GuardPolicy(model: Document::class)]
class DocumentsPolicy
{
    #[GateAbility(permission: DocumentsPermission::View)]
    public function canView(User $user, Document $document): bool
    {
        return $user->hasPermission(DocumentsPermission::View)
            && $user->id === $document->owner_id;
    }

    #[GateAbility(permission: DocumentsPermission::Edit)]
    public function canEdit(User $user, Document $document): bool
    {
        return $user->hasPermission(DocumentsPermission::Edit)
            && ! $document->isLocked();
    }

    #[GateAbility(permission: DocumentsPermission::Delete)]
    public function canDelete(User $user, Document $document): bool
    {
        return $user->hasPermission(DocumentsPermission::Delete);
    }
}
```

## Gate::before — wildcards only

`Gate::before` is registered by AzGuard to short-circuit for super-admin roles. A role whose `permissions()` returns `['*']` causes `Gate::before` to return `true` **without calling the policy**.

No other logic runs in `Gate::before`. All real permission checks happen in policy methods via `hasPermission()`.

## Calling the Gate

Laravel's **native** Gate matches against the full, panel-prefixed key. Passing a bare enum case to `Gate::allows()` / `Gate::authorize()` does **not** work — Laravel normalises the enum to its unscoped `->value`, which never matches the panel-scoped key. Pass the full string key, or derive it from the enum with `AzGuard::permission($panelId, $case)` to stay typo-proof:

```php
use AzGuard\Facades\AzGuard;

// ✅ In a controller — full panel-prefixed key (derived from the enum)
Gate::allows(AzGuard::permission('app', DocumentsPermission::View), $document);
Gate::authorize(AzGuard::permission('app', DocumentsPermission::Edit), $document);

// ✅ Or the plain full key when readability matters
Gate::allows('app.documents.view', $document);

// For a capability check that *is* enum-aware, use the trait instead of the Gate
$user->hasPermission(DocumentsPermission::View);
```

```blade
{{-- ✅ Option 1: pre-resolved boolean from controller (preferred) --}}
@if($can['delete'])
    <button>Delete</button>
@endif

{{-- ✅ Option 2: native @can with the full panel-prefixed key --}}
@can('app.documents.delete', $document)
    <button>Delete</button>
@endcan

{{-- ✅ AzGuard directive — enum-aware, pass the enum case directly --}}
@azcan(DocumentsPermission::View)
    <a href="...">View</a>
@endazcan
```

::: tip Enum vs string
The `@azcan` directive and `$user->hasPermission()` are enum-aware — pass the enum case directly and it is scoped to the panel automatically. Laravel's **native** Gate (`Gate::allows()`, `Gate::authorize()`, `@can`, `$user->can()`) is not: it needs the full panel-prefixed key (`app.documents.view`), so derive it with `AzGuard::permission(...)` rather than passing the bare enum.
:::
