# Introduction

AzGuard is an authorization package for Laravel. You define permissions as PHP enums and roles as PHP
classes, and you keep them in Git. The database stores only **who holds what**: role grants and permission
grants. Each grant can be scoped and can expire.

```php
#[Role('editor', label: 'Editor')]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [PostPermission::View, PostPermission::Update];
    }
}

$user->grantRole(EditorRole::class);
$user->hasPermission(PostPermission::Update, $post); // the grant decides, PostPolicy may veto
$user->can('update', $post);                         // the same decision through Laravel's Gate
```

## What you get

- **Panels.** A panel is one area of the application, such as `admin` or `cabinet`. Each panel has its
  own permissions, roles, sources of grants, tenant rules and settings. A user can have different roles in
  different panels.
- **Typed definitions.** Permissions are backed enum cases and roles are classes, so renames are caught by your
  IDE and PHPStan. The package discovers both from the panel directory.
- **Grants with context.** A grant can be tenant-wide or limited to one scope, such as a team or a project.
  It can also expire (`until:`). Every change records an actor, publishes events and raises the revision of
  the affected subject, which invalidates that subject's cached grants.
- **One decision pipeline.** The trait methods, the facade, the middleware, the Gate and the Filament
  plugin all reach the same engine. Policies can veto a granted permission. A failing component denies
  access instead of allowing it.
- **Diagnostics.**
  - `azguard:doctor` validates the configuration, panels, storages and sources.
  - `azguard:explain` shows every stage of one decision.
- **Testing kit.**
  - `actingAsWithPermissions()` and `actingAsSuperAdmin()` work with real grants, also under `RefreshDatabase`.
  - `AzGuard::fake()` records checks and changes.
- **Filament 5.** The `axiomasoft/azguard-filament` package authorizes resources, pages and widgets. It also
  provides editors for roles and grants.

## When AzGuard is not the right tool

- **Fully dynamic roles.** If administrators must create roles and permissions at runtime without deploying
  code, a database-first package fits better. Examples are `spatie/laravel-permission` and `silber/bouncer`.
  AzGuard supports dynamic *permissions* (`DatabaseSource::make()->dynamicPermissions()`), but roles are
  always code.
- **A policy language.** If you need a general model such as ABAC rules in a DSL shared with other
  languages, look at Casbin. See the [comparison](/comparison).
- **A few abilities only.** Laravel's own Gate and policies are enough when a handful of abilities need no
  stored grants.

## Requirements

| | Supported |
|---|---|
| PHP | 8.3, 8.4, 8.5 (8.5 with Laravel 13) |
| Laravel | 11, 12, 13 |
| Databases (tested in CI) | SQLite, MySQL 8, MariaDB 10.11, PostgreSQL 16 |
| Filament (optional) | 5.x, via `axiomasoft/azguard-filament` |

Next: [Installation](/getting-started/installation).
