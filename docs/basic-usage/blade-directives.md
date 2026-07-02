# Blade Directives

AzGuard integrates with Laravel's native Gate layer, which means **all standard Laravel Blade directives work out of the box** — no additional setup required.

::: tip Permission-first
Always prefer **permission directives** (`@can`, `@cannot`, `@canany`, `@azcan`) over the role directive (`@azrole`). Permissions are stable; roles change. Code that checks permissions stays valid even when you rename or restructure roles.
:::

## Passing permissions from the controller

Blade templates have no `use` statements, so the cleanest pattern is to **resolve permissions once in the controller** and pass pre-computed booleans to the view:

```php
// DocumentController
public function show(Document $document): Response
{
    return view('documents.show', [
        'document' => $document,
        'can' => [
            'edit'   => Gate::allows(DocumentsPermission::Edit,   $document),
            'delete' => Gate::allows(DocumentsPermission::Delete, $document),
        ],
    ]);
}
```

```blade
{{-- ✅ Clean — no strings, no FQCN --}}
@if($can['edit'])
    <a href="{{ route('documents.edit', $document) }}" class="btn">Edit</a>
@endif

@if($can['delete'])
    <button class="btn-danger">Delete</button>
@endif
```

This is the **recommended pattern**: permission logic stays in PHP where it belongs, templates stay clean, and the check is type-safe at the source.

## Permission directives

When you must use Gate directives directly in a template (e.g. layouts, shared partials), use the enum `->value` property or the fully-qualified class name:

### `@can` / `@cannot`

```blade
{{-- ✅ FQCN with ->value --}}
@can(\App\Guards\App\Documents\Permissions\DocumentsPermission::Edit->value)
    <a href="{{ route('documents.edit', $document) }}" class="btn">Edit</a>
@endcan

@cannot(\App\Guards\App\Documents\Permissions\DocumentsPermission::Delete->value)
    <p class="text-muted">You don't have permission to delete documents.</p>
@endcannot

{{-- With an else branch --}}
@can(\App\Guards\App\Documents\Permissions\DocumentsPermission::Create->value)
    <a href="{{ route('documents.create') }}">New document</a>
@else
    <span class="text-muted">Read-only access</span>
@endcan
```

### `@canany`

Passes if the user has **at least one** of the listed permissions:

```blade
@canany([
    \App\Guards\App\Documents\Permissions\DocumentsPermission::Create->value,
    \App\Guards\App\Documents\Permissions\DocumentsPermission::Edit->value,
])
    <div class="editor-toolbar">
        {{-- shown to users who can either create or edit --}}
    </div>
@endcanany
```

There is no `@canall` directive. To check for all permissions, chain multiple `@can` blocks or combine `hasPermission()` calls in a condition.

### `@azcan`

AzGuard's own permission directive. It calls `$user->hasPermission()` directly, so you pass the **full panel-prefixed key** (or an enum case):

```blade
@azcan('app.documents.view')
    <a href="/documents">Documents</a>
@endazcan

{{-- With an else branch --}}
@azcan('app.documents.create')
    <a href="{{ route('documents.create') }}">New document</a>
@elseazcan('app.documents.view')
    <span class="text-muted">Read-only access</span>
@endazcan

{{-- Inverse check --}}
@unlessazcan('app.documents.delete')
    <p class="text-muted">You don't have permission to delete documents.</p>
@endunlessazcan
```

### With model arguments (Policy-style)

```blade
{{-- Routes through Laravel's Policy system if a policy exists for Document --}}
@can(\App\Guards\App\Documents\Permissions\DocumentsPermission::Edit->value, $document)
    <button type="button">Edit</button>
@endcan
```

### Checking with a specific guard

```blade
{{-- Useful in multi-guard apps --}}
@can(\App\Guards\Admin\Users\Permissions\UsersPermission::Manage->value, 'admin')
    <a href="/admin/users">Manage users</a>
@endcan
```

## Role directives

::: warning Use permissions instead of roles in templates
Role checks in templates create tight coupling between your UI and your role structure. When you rename or split a role, you must update every template. Permission checks are stable — use them wherever possible.

The only legitimate use of role directives is displaying role-specific UI chrome (e.g. "Admin Panel" link in the nav) where the *concept of a role* is what matters, not a specific capability.
:::

### `@azrole`

AzGuard's role directive. It calls `$user->hasRole()`, so pass a single role name:

```blade
@azrole('admin')
    <a href="/admin">Admin panel</a>
@endazrole
```

To check for one of several roles, combine conditions in PHP and expose a boolean, or chain checks:

```blade
@if(auth()->user()?->hasRole('editor') || auth()->user()?->hasRole('admin'))
    <div class="editor-toolbar">...</div>
@else
    <div class="read-only">...</div>
@endif
```

::: tip
There is no built-in "any role" / "all roles" / "exact roles" directive. Resolve those conditions in the component or controller (see below) and pass a boolean to the view — it keeps templates clean and the logic testable.
:::

## In Livewire components

Prefer computing access in the component class and exposing it as a read-only property or method:

```php
// In your Livewire component class
public function canEdit(): bool
{
    return $this->user->hasPermission(DocumentsPermission::Edit);
}
```

```blade
@if($this->canEdit())
    <button wire:click="edit">Edit</button>
@endif
```

This keeps authorization logic out of templates and makes it easy to test.

## In controller conditions

For non-Gate checks in controllers you can also use `@if` with the trait methods:

```blade
@if(auth()->user()?->hasRole('admin'))
    <a href="/admin">Admin panel</a>
@endif

@if(auth()->user()?->hasPermission(DocumentsPermission::Delete))
    <button class="btn-danger">Delete</button>
@endif
```

## Performance note

Gate checks with AzGuard are resolved per-request from in-memory role definitions and cached within the request lifecycle. Calling `@can(DocumentsPermission::View->value)` multiple times on the same page does **not** re-query the database.
