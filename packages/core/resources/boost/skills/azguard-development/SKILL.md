---
name: azguard-development
description: Use AzGuard (code-first RBAC for Laravel) — panels, enum/class permissions, roles, direct grants and checks. Activate when adding authorization to a Laravel app that depends on axioma-studio/azguard-core.
---

# Using AzGuard

AzGuard is **code-first**: roles, permissions and panels are PHP **enums and
classes**, never magic strings. Authorization is refactor-safe and reviewable.

## Core concepts

- **Panel** — an isolated authorization scope (`app`, `admin`, `api`…). Every
  permission key is `"{panel}.{value}"`. Register panels in `config/az-guard.php`.
- **Permission** — a backed enum case (preferred) or a class implementing
  `AzGuard\Contracts\Permission`. The owning panel scopes it automatically.
- **Role** — a PHP class extending `AzGuard\Roles\BaseRole`; its `permissions()`
  returns enum cases.
- **GrantSource** — resolves a user's permissions (class roles, DB roles, direct
  grants). Pluggable via `AzGuard::registerGrantSource()`.
- **Catalog builder** — discovers valid permission keys for a panel. Pluggable
  via `AzGuard::registerCatalogBuilder()` (see *Extending* below).

## Setup (do this first)

1. `composer require axioma-studio/azguard-core`
2. `php artisan guard:install` (publishes config + migrates)
3. Add `use AzGuard\Concerns\HasAzGuard;` to the `User` model.
4. `php artisan make:guard-panel App Documents` — scaffolds a panel (provider +
   permission enum + policy + role) and auto-registers it in config.

## Declaring permissions and roles

```php
// app/Guards/App/Documents/Permissions/DocumentsPermission.php
enum DocumentsPermission: string
{
    case View   = 'documents.view';
    case Create = 'documents.create';
}

// app/Guards/App/Roles/EditorRole.php
class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [DocumentsPermission::View, DocumentsPermission::Create];
    }
}
```

The panel provider must register the enum: `->permissionEnums([DocumentsPermission::class])`
(the scaffold does this). Then run `php artisan guard:sync-roles` to mirror role
classes into the DB so they can be assigned.

## Assigning and checking — ALWAYS prefer enums/classes

```php
$user->assignRole(EditorRole::class);              // not 'editor'
$user->hasRole(EditorRole::class);                 // bool

$user->hasPermission(DocumentsPermission::View);   // enum — scoped to the panel
```

In Blade use `@azcan(DocumentsPermission::View) … @endazcan` (enum-aware).
Laravel's native Gate needs the full key: `$user->can('app.documents.view')`.

## Direct grants (per-user, optional TTL)

```php
AzGuard::forUser($user)->on('app')->ttl(3600)->grant(DocumentsPermission::Create);
AzGuard::forUser($user)->on('app')->revoke(DocumentsPermission::Create);
```

## Frontend abilities (share to JS/Inertia)

Never dump the full catalog. Resolve an explicit allowlist of keys to a
`ability => bool` map for shared props:

```php
$abilities = AzGuard::abilitiesFor($user, 'app', [
    'app.documents.view',
    'app.documents.create',
]);
```

For a typed, reusable projection, generate an `AbilitiesDto` subclass and build
it once:

```bash
php artisan make:guard-abilities App Documents   # or make:guard-panel --with-abilities
```

```php
$payload = DocumentsAbilities::make($user)->toArray();   // array<string, bool>
```

## Super-admin

`php artisan guard:super-admin --user=1` grants the wildcard role that
short-circuits every check via `Gate::before()`. Test for it with
`$user->isSuperAdmin()` (optionally scoped: `$user->isSuperAdmin('admin')`).

## Extending

Register custom resolution/discovery from a service provider's `register()`:

```php
AzGuard::registerGrantSource(MyGrantSource::class);        // extra permission source
AzGuard::registerCatalogBuilder(MyCatalogBuilder::class);  // extra key discovery
```

## Rules

- NEVER use magic permission strings in PHP — use enum cases or `Permission`
  classes. Strings are only for Blade/Gate/config where enums aren't natural.
- A role's enum must be registered on its panel via `->permissionEnums([...])`.
- Run `php artisan guard:doctor` to diagnose misconfiguration.
- Run `php artisan about` to see panels, version and cache store.
