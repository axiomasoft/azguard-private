# AzGuard

[![Tests](https://github.com/axioma-studio/azguard/actions/workflows/tests.yml/badge.svg)](https://github.com/axioma-studio/azguard/actions/workflows/tests.yml)
[![Code Style](https://github.com/axioma-studio/azguard/actions/workflows/code-style.yml/badge.svg)](https://github.com/axioma-studio/azguard/actions/workflows/code-style.yml)
[![PHPStan](https://github.com/axioma-studio/azguard/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/axioma-studio/azguard/actions/workflows/static-analysis.yml)
[![Latest Version](https://img.shields.io/packagist/v/axioma-studio/azguard-core.svg?style=flat-square)](https://packagist.org/packages/axioma-studio/azguard-core)
[![Total Downloads](https://img.shields.io/packagist/dt/axioma-studio/azguard-core.svg?style=flat-square)](https://packagist.org/packages/axioma-studio/azguard-core)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](LICENSE)

**Code-first** role-based access control for Laravel. Roles, permissions and panels are PHP **enums and classes** — not magic strings — so authorization is refactor-safe, IDE-autocompletable and reviewable in pull requests.

> 🇷🇺 Русская версия — [README.ru.md](README.ru.md).

---

## Why AzGuard, not Spatie?

| | AzGuard | spatie/laravel-permission |
|---|---|---|
| Source of truth | **Code** (enums, classes) | Database rows |
| Permissions | **Enum cases** / classes | Strings |
| Refactor safety | ✅ rename = IDE refactor | ❌ grep magic strings |
| Multi-panel scopes | ✅ built-in | ❌ |
| PHP Attributes | ✅ `#[CheckPermission]`, `#[GateAbility]` | ❌ |
| Policy autodiscovery | ✅ | ❌ |

---

## Installation

```bash
composer require axioma-studio/azguard-core
php artisan guard:install
```

`guard:install` publishes the config, runs the migrations and prints the next steps. Then add the trait to your `User` model:

```php
use AzGuard\Concerns\HasAzGuard;

class User extends Authenticatable
{
    use HasAzGuard;
}
```

---

## Quick start (panel-first, enum-first)

### 1. Create a panel

```bash
php artisan make:guard-panel App Documents
```

A **panel** is an isolated authorization scope (`app`, `admin`, `api`…). This scaffolds `app/Guards/App/` — a panel provider, a permission enum, a policy and a role — and **registers the panel in `config/az-guard.php` for you**. The generated permission enum:

```php
namespace App\Guards\App\Documents\Permissions;

enum DocumentsPermission: string
{
    case ViewAny = 'documents.view_any';
    case View    = 'documents.view';
    case Create  = 'documents.create';
    case Update  = 'documents.update';
    case Delete  = 'documents.delete';
}
```

### 2. Declare a role with enum permissions

```php
namespace App\Guards\App\Roles;

use App\Guards\App\Documents\Permissions\DocumentsPermission;
use AzGuard\Roles\BaseRole;

class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [
            DocumentsPermission::View,
            DocumentsPermission::Create,
            DocumentsPermission::Update,
        ];
    }
}
```

No `"app.documents.view"` strings — the panel scopes each enum case automatically.

### 3. Register the code roles in the database

```bash
php artisan guard:sync-roles
```

This mirrors your PHP role classes into the `roles` table so they can be assigned. Safe to run in CI/CD.

### 4. Assign roles and check permissions — by class and enum

```php
// Assign by class — unambiguous and refactor-safe
$user->assignRole(EditorRole::class);
$user->hasRole(EditorRole::class);                 // true

// Check with the enum case — scoped to the panel automatically
$user->hasPermission(DocumentsPermission::View);   // true

// In Blade — also enum-aware
@azcan(DocumentsPermission::View)
    <a href="/documents">Documents</a>
@endazcan

// Laravel's native Gate uses the full panel-prefixed key
$user->can('app.documents.view');                  // true
Gate::allows('app.documents.view');                // true
```

### 5. A super-admin in one command

```bash
php artisan guard:super-admin --user=1
```

Grants the wildcard role that short-circuits every check via `Gate::before()` — the fastest path to a working login.

---

## Class-based permissions (open sets)

For open or multi-module permission sets where a closed enum is too rigid, implement the `Permission` contract and reference it by `::class`:

```php
use AzGuard\Contracts\Permission;

final class UpdatePost implements Permission
{
    public static function ability(): string
    {
        return 'posts.update';
    }
}

$user->hasPermission(UpdatePost::class, 'app');    // -> "app.posts.update"
```

---

## Console commands

| Command | Description |
|---|---|
| `guard:install` | Publish config + run migrations (guided) |
| `make:guard-panel {Panel} {Domain}` | Scaffold a panel (auto-registers in config) |
| `make:guard-permission` | Generate a permission enum |
| `make:guard-role` | Generate a role class |
| `guard:sync-roles` | Mirror PHP role classes into the `roles` table |
| `guard:super-admin --user=` | Promote a user to super-admin |
| `guard:doctor` | Diagnose the configuration |
| `guard:list-permissions {panel?}` | List registered permissions |
| `guard:cache-reset` | Flush the permission cache |

`php artisan about` shows AzGuard's version, registered panels and cache store.

---

## Caching

Per-request, in-memory by default. For cross-request caching via Redis:

```php
// config/az-guard.php
'cache' => [
    'store'           => 'redis',
    'expiration_time' => 3600,
],
```

Reset with `php artisan guard:cache-reset`.

---

## Packages

- **`axioma-studio/azguard-core`** — roles, permissions, panels, direct grants
- **`axioma-studio/azguard-filament`** — Filament admin UI
- **`axioma-studio/azguard-context`** — multi-workspace / multi-site context

---

## Testing & quality

```bash
composer test      # Pest
composer check     # every CI gate locally: code style + static analysis + refactor + tests
composer fix       # auto-fix code style and apply refactorings
```

---

## Upgrading

This is the initial `0.1` release. See [UPGRADING.md](UPGRADING.md) for migration
notes as future versions ship.

## Security

If you discover a security vulnerability, please email dv.vostrikov@gmail.com instead of using the issue tracker. See [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
