# Prerequisites

Before installing AzGuard, make sure your environment meets the following requirements.

## System requirements

| Requirement | Minimum version |
|---|---|
| PHP | 8.3+ |
| Laravel | 11.x or 12.x |
| Database | MySQL 8 / PostgreSQL 14 / SQLite 3.35+ |

## PHP extensions

AzGuard uses standard Laravel infrastructure. No uncommon extensions are needed beyond what a default Laravel install provides:

- `ext-pdo` — database access
- `ext-json` — serialization
- `ext-mbstring` — string handling

## User model: `Authorizable` contract

AzGuard plugs into Laravel's Gate layer. For `can()`, `authorize()`, and policy methods to work in your controllers, policies, and Blade templates, your `User` model **must implement** the `Illuminate\Contracts\Auth\Access\Authorizable` contract.

The easiest way is to extend Laravel's built-in `Authenticatable` base class (which already implements the contract), then add the `HasAzGuard` trait:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use AzGuard\Concerns\HasAzGuard;

class User extends Authenticatable
{
    use HasAzGuard;
}
```

If you are using a custom base class, make sure it implements `Illuminate\Contracts\Auth\Access\Authorizable` — otherwise `$user->can()` and `Gate::allows()` will silently return `false` for all checks.

## Reserved property and method names

::: danger Name conflicts will cause silent bugs
Do **not** define any of the following on your `User` model (or any model that uses `HasAzGuard`). These names are used internally by AzGuard traits — collisions cause unexpected behaviour that is hard to debug:

- `role` / `roles` — property, database column, Eloquent relation, or method `roles()`
- `permission` / `permissions` — property, database column, Eloquent relation, or method `permissions()`
:::

If your existing model already has one of these names, rename it before installing AzGuard. For example, a legacy `$user->roles` array property should become `$user->role_list` or similar.

## Config file

Installing AzGuard publishes `config/az-guard.php`. If you already have a file with that name, rename or remove it first — otherwise the publish command will skip it and your application will use the old file.

```bash
# Check before publishing
ls config/az-guard.php

# Publish fresh
php artisan vendor:publish --tag=az-guard-config
```

## Database schema: index key length (MySQL)

::: warning MySQL utf8mb4 index length
MySQL 8+ with `utf8mb4` limits compound index key lengths. AzGuard's pivot tables use compound indexes that may hit these limits depending on your `ROW_FORMAT` setting.

Choose one of the following approaches before running migrations:

**Option 1 (recommended):** Configure MySQL to use InnoDB with `ROW_FORMAT=Dynamic` (the default in MySQL 8.0+). No code changes needed.

**Option 2:** In your `AppServiceProvider::boot()`, cap the default string length:
```php
use Illuminate\Support\Facades\Schema;

public function boot(): void
{
    Schema::defaultStringLength(125);
}
```

**Option 3:** After publishing migrations, manually specify shorter field lengths:
```php
$table->string('name', 125);
$table->string('guard_name', 25);
```
:::

## UUID / ULID primary keys

AzGuard's default migrations assume an **auto-incrementing integer** primary key on your `User` model. If your application uses UUIDs, ULIDs, or any non-integer PK, set the morph key type in `config/az-guard.php` **before** running migrations:

```php
'column_names' => [
    'morph_type' => 'ulid', // or 'uuid' — default is 'int'
],
```

The migrations read this value and create the polymorphic columns with the matching type. (You can also set the `AZ_GUARD_MORPH_TYPE` environment variable.)

See [UUID / ULID](/advanced/uuid-ulid) for the complete walkthrough.

## Foreign key constraints

AzGuard migrations create foreign-key constraints with `onDelete('cascade')` on all pivot tables. This ensures referential integrity — deleting a user or a role automatically cleans up their assignments.

If your database engine does **not** support foreign keys (e.g. older MyISAM tables, some SQLite configs), you must either:

- Switch to InnoDB / WAL mode, or
- Publish the migrations and remove the `->foreign()` calls before migrating

As long as you only manage assignments through AzGuard's own methods (`assignRole`, `removeRole`, `grant`, `revoke`, etc.), data integrity is maintained even without FK constraints — the package handles pivot cleanup in PHP.

## Multi-guard apps

If your application uses multiple [guards](https://laravel.com/docs/authentication#introduction) (e.g. `web` and `api`), each guard has its own independent set of roles and permissions in AzGuard. See [Multiple Guards](/basic-usage/multiple-guards) for setup details.

## Octane compatibility

AzGuard is stateless by design. There are no static caches that survive between requests, so it works safely with [Laravel Octane](https://laravel.com/docs/octane) (Swoole & RoadRunner) out of the box.
