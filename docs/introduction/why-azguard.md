# What is AzGuard?

AzGuard is a **code-first RBAC package for Laravel**. Roles are PHP classes. Permissions are enum cases. The database stores only user ↔ role assignments and direct grants — not the permission definitions themselves.

## The problem with DB-first RBAC

Every other popular Laravel permission package (Spatie, Bouncer, Laratrust) stores the permission catalog in the database. That creates a set of recurring problems at scale:

- **Magic strings** like `'edit-posts'` scattered across controllers, seeders, tests, and docs — no IDE support, no static analysis
- **Typos that fail at runtime**, not at CI
- **Diverging state** between dev, staging, and production databases
- **Cache poisoning** under Octane or Kubernetes when shared app memory bleeds between requests
- **Unmergeable diffs** — schema migrations for every new permission, impossible to review in a PR

## The AzGuard approach

Permissions live in PHP. The database is only ever asked "which role does this user have?" — never "what does this permission allow?"

```php
// Permission — a plain backed enum. IDE-completable. PHPStan-checkable. Git-trackable.
// Values are unscoped; the panel prefixes them.
enum DocumentsPermission: string
{
    case View   = 'documents.view';
    case Edit   = 'documents.edit';
    case Delete = 'documents.delete';
}

// Role — a PHP class. Readable. Testable. Diffable.
use App\Guards\App\Documents\Permissions\DocumentsPermission;

class EditorRole extends BaseRole
{
    public function getName(): string { return 'editor'; }
    public function getLevel(): int   { return 10; }

    public function permissions(): array
    {
        // Enum cases — the panel scopes each one automatically (no "app." prefix)
        return [
            DocumentsPermission::View,
            DocumentsPermission::Edit,
        ];
    }
}
```

When you add or rename a permission, the change is a **PHP file diff** — reviewable, searchable, and blocked by CI if a reference breaks.

## Key capabilities

### Panel namespacing

Permissions are scoped to a panel. `app.posts.edit` and `admin.posts.edit` are completely isolated — an app-panel role can never accidentally grant admin access.

### Custom (runtime) roles

In addition to static PHP-class roles, AzGuard supports **DB-backed custom roles** created at runtime via Filament or API. Both kinds resolve through the same permission check path.

### PHP 8 Attributes

```php
// Declarative, visible in route inspection tools, no authorize() in the body
#[CheckPermission(permission: DocumentsPermission::Edit, arguments: ['document'])]
public function update(UpdateDocumentRequest $request, Document $document): Response
{
    // ...
}
```

### Native Gate integration

AzGuard hooks into `Gate::before()`. Every standard Laravel auth primitive works unchanged:

```php
Gate::allows('app.posts.edit');      // ✅
$this->authorize('update', $post);   // ✅ via policy
@can('app.posts.edit') ... @endcan  // ✅
```

### Built-in diagnostics

```bash
php artisan guard:doctor            # finds orphaned policies, typos, missing abilities
php artisan guard:list-permissions  # shows all permissions across all panels
php artisan guard:sync-roles        # syncs PHP role classes to DB
```

## When AzGuard is the right choice

**Use AzGuard when:**
- Your app has multiple access zones (admin, app, API)
- You want permissions reviewable in pull requests
- You use Octane or deploy to Kubernetes
- You need multi-panel RBAC with strict namespace isolation
- You want IDE support and PHPStan on access control

**Consider alternatives when:**
- You need non-developers to configure permissions from scratch at runtime (pure DB-driven workflow)
- You're on a tiny app with 2-3 fixed roles that never change
- You're migrating a legacy app deeply integrated with Spatie's DB schema

→ [Detailed comparison with Spatie, Bouncer, and Laratrust](/introduction/comparison)
