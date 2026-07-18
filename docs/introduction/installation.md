# Installation

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.3+ |
| Laravel | 11.x, 12.x, or 13.x |
| Database | MySQL 8+, PostgreSQL 13+, SQLite 3.35+ |

## Install via Composer

```bash
composer require axioma-studio/azguard-core
```

## Publish the config

```bash
php artisan vendor:publish --tag=az-guard-config
```

This creates `config/az-guard.php`. See the [Configuration reference](/advanced/configuration) for all options.

## Run migrations

```bash
php artisan migrate
```

These tables are created (names match `config/az-guard.php` defaults):

| Table | Purpose |
|---|---|
| `roles` | Dynamic role definitions |
| `model_has_roles` | User → role assignments |
| `model_has_scopes` | User → scoped role assignments |
| `az_guard_role_permissions` | Dynamic role → permission keys |
| `az_direct_grants` | Per-user direct permission grants |

## Add the trait

```php
use AzGuard\Concerns\HasAzGuard;

class User extends Authenticatable
{
    use HasAzGuard;
}
```

## Optional: customize table names

AzGuard's migrations read table names from `config/az-guard.php`. If you need different names, publish the config and edit the `table_names` section **before** running migrations:

```bash
php artisan vendor:publish --tag=az-guard-config
# Edit config/az-guard.php → table_names
php artisan migrate
```

## Verify installation

```bash
php artisan guard:doctor
```

`guard:doctor` inspects every registered panel for duplicate abilities, enum
cases missing a `#[GateAbility]` policy method, roles referencing unknown
permissions, orphan policies, and stale `scope_class` values in
`model_has_scopes`. On a fresh install (no panels registered yet) there is
nothing to check, so it prints:

```
guard:doctor: all checks passed.
```

## Laravel Octane

AzGuard is Octane-safe. The permission resolver uses per-request state and does not share memory between requests. No additional configuration is needed.

## Testing environment

When running tests, use `RefreshDatabase` and keep the permission cache in-memory by setting `cache.store` to `'array'` (the default). Then assert against the real API:

```php
$user->assignRole('editor');

expect($user->hasPermission('app.documents.view'))->toBeTrue();
expect(Gate::forUser($user)->allows('app.documents.view'))->toBeTrue();
```

With `cache.store = 'array'` no permission state leaks between test cases.

See [Testing](/advanced/testing) for the full guide.
