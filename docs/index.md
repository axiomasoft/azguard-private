---
layout: home

hero:
  name: ""
  text: ""
  tagline: ""
  image:
    src: /logo.png
    alt: AzGuard
  actions:
    - theme: brand
      text: Get Started →
      link: /introduction/quick-start
    - theme: alt
      text: Why AzGuard?
      link: /introduction/why-azguard
    - theme: alt
      text: View on GitHub
      link: https://github.com/axioma-studio/azguard
---

<div class="az-hero-text">
  <h1>Your permissions live in Git,<br>not in the dark.</h1>
  <p class="az-lead">AzGuard is a <strong>code-first RBAC package for Laravel</strong>. Roles are PHP classes. Permissions are typed enum cases. The database only stores user→role assignments — never the permission catalog itself.</p>
</div>

---

## Why developers choose AzGuard

<div class="az-features">

**🏗️ Code-first — no magic strings**
Permissions are PHP enum cases. Rename one and your IDE and PHPStan catch every broken reference before CI does. No more `'edit-posts'` typos that only fail at runtime.

**🎛️ Multi-panel isolation**
`app.*`, `admin.*`, `api.*` namespaces are completely independent. A single User model can carry roles across multiple access contexts without cross-contamination.

**⚡ Native Laravel Gate**
AzGuard hooks into `Gate::before()`. Every standard primitive — `@can`, `Gate::allows()`, `$this->authorize()`, policies — works unchanged. No new API to learn.

**🩺 Built-in diagnostics**
`artisan guard:doctor` scans your config, migrations, and role definitions and reports mismatches before they reach production.

**🎯 Direct Grants with TTL**
Grant a single permission to a single user for 1 hour without touching roles. Perfect for beta features, temporary overrides, and time-limited export access.

**🔌 Context-aware (opt-in)**
Attach a runtime context (tenant, team, project) to every permission check. Zero overhead when not used.

</div>

---

## Three lines that tell the whole story

::: code-group

```php [1. Define]
// app/Guards/App/Documents/Permissions/DocumentsPermission.php
// Values are unscoped; the panel prefixes them to 'app.documents.*'.
enum DocumentsPermission: string
{
    case View   = 'documents.view';
    case Create = 'documents.create';
    case Edit   = 'documents.edit';
    case Delete = 'documents.delete';
}

// Register the enum on the panel (in your PanelProvider):
// $panel->id('app')->permissionEnums([DocumentsPermission::class]);
```

```php [2. Protect]
// Declarative PHP 8 attribute — visible in route inspection, zero boilerplate
#[CheckPermission(DocumentsPermission::View)]
public function index(): Response
{
    return Inertia::render('Documents/Index');
}

// With model binding — routes through your Policy automatically
#[CheckPermission(permission: DocumentsPermission::Edit, arguments: ['document'])]
public function update(UpdateDocumentRequest $request, Document $document): Response
{
    $document->update($request->validated());
    return back();
}
```

```php [3. Check]
// On the User model — enum case or full string key
$user->hasPermission(DocumentsPermission::View);   // true / false
$user->hasPermission('app.documents.view');          // same result

// Laravel Gate — works everywhere Gate works
Gate::allows('app.documents.view');                  // ✅
$this->authorize('update', $document);               // ✅ via Policy

// Blade
@can('app.documents.edit')
    <a href="{{ route('documents.edit', $doc) }}">Edit</a>
@endcan

// Roles relation
$user->roles()->where('name', 'editor')->exists();
```

:::

---

## Quick install

```bash
composer require axioma-studio/azguard-core
php artisan vendor:publish --tag=az-guard-config
php artisan migrate
```

Then add the trait to your User model:

```php
use AzGuard\Concerns\HasAzGuard;

class User extends Authenticatable
{
    use HasAzGuard;
}
```

→ [Full installation guide](/introduction/installation) · [Quick Start in 5 minutes](/introduction/quick-start)

---

## How it compares

| | AzGuard | Spatie Permission | Bouncer |
|---|:---:|:---:|:---:|
| Permissions as PHP code | ✅ | ❌ | ❌ |
| IDE autocompletion on permissions | ✅ | ❌ | ❌ |
| Static analysis (PHPStan) on permissions | ✅ | ❌ | ❌ |
| Permissions diffable in PRs | ✅ | ❌ | ❌ |
| Multi-panel namespace isolation | ✅ | ❌ | ❌ |
| Direct Grants with TTL | ✅ | ❌ | ✅ |
| Octane / Kubernetes safe (no shared state) | ✅ | ⚠️ | ✅ |
| Native Gate integration | ✅ | ✅ | ✅ |
| Runtime (DB) role creation | ✅ | ✅ | ✅ |
| Built-in diagnostics (`guard:doctor` command) | ✅ | ❌ | ❌ |

→ [Full comparison with Spatie, Bouncer, and Laratrust](/introduction/comparison)
