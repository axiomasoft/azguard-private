<p align="center"><img src="art/logo.png" width="128" height="128" alt="AzGuard logo"></p>

# AzGuard

[![Tests](https://github.com/axiomasoft/azguard-private/actions/workflows/tests.yml/badge.svg)](https://github.com/axiomasoft/azguard-private/actions/workflows/tests.yml)
[![PHPStan](https://github.com/axiomasoft/azguard-private/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/axiomasoft/azguard-private/actions/workflows/static-analysis.yml)
[![Latest Version](https://img.shields.io/packagist/v/axiomasoft/azguard.svg)](https://packagist.org/packages/axiomasoft/azguard)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Code-first authorization for Laravel 11–13 with an optional Filament 5 plugin.

- Permissions are backed enums and roles are PHP classes. Grants live in your database.
- Every check goes through one decision pipeline: `hasPermission()`, `@can`, middleware, controller attributes,
  Filament and list queries.
- An error denies. `azguard:explain` shows why.

```php
#[RequiresGrant]
enum PostPermission: string
{
    case View = 'posts.view';
    case Update = 'posts.update';
}

#[Role('editor')]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [PostPermission::View, PostPermission::Update];
    }
}

$user->grantRole(EditorRole::class, on: $team, until: now()->addWeek());
$user->hasPermission(PostPermission::Update, $post);   // the grant, then PostPolicy may veto
```

## Installation

```bash
composer require axiomasoft/azguard
php artisan azguard:install --panel=Admin --migrate
```

Add `HasAzGuard` and `AzGuardSubject` to your `User` model and register a morph alias. Then follow the
**[quick start](docs/getting-started/quick-start.md)**.

For Filament: `composer require axiomasoft/azguard-filament`. See the
[Filament guide](docs/guides/filament.md).

## Features

- **Panels.** Isolated permission sets for an admin area, a cabinet or an API, each with its own subjects,
  roles and settings.
- **Authority modes.** `#[RequiresGrant]`: a grant is required and a policy may veto. `#[PolicyOnly]`: the
  policy decides. `#[GrantedToAll]`.
- **Grants.** Wildcards (`posts.*`, `posts.**`), expiry with pruning, custom grant fields, runtime
  permissions.
- **Tenants and assignment scopes.** A role on one team, inherited by its sub-scopes, with tenant membership
  checks.
- **Lists.** `visibleTo()` filters an Eloquent query to the records a user may see, in SQL.
- **Extensibility.** Custom sources (LDAP, an existing membership table), before hooks, restrictions, change
  pipes, plugins and an audit journal.
- **Events.** Published after commit, with actor and reason.
- **Testing.** `actingAsWithRoles()`, `AzGuard::fake()`, contract tests for extension authors.
- **Operations.** `azguard:doctor` for CI, a catalog cache, Octane-safe request state.

## Documentation

| | |
|---|---|
| Getting started | [Introduction](docs/getting-started/introduction.md) · [Installation](docs/getting-started/installation.md) · [Quick start](docs/getting-started/quick-start.md) |
| Concepts | [Panels](docs/concepts/panels.md) · [Permissions](docs/concepts/permissions.md) · [Roles](docs/concepts/roles.md) · [Policies](docs/concepts/policies.md) · [Decisions](docs/concepts/decisions.md) |
| Guides | [Checking access](docs/guides/checking-access.md) · [Granting access](docs/guides/granting-access.md) · [Tenants and scopes](docs/guides/tenants-and-scopes.md) · [Filament](docs/guides/filament.md) · [Testing](docs/guides/testing.md) |
| Reference | [Configuration](docs/reference/configuration.md) · [Commands](docs/reference/commands.md) · [Events](docs/reference/events.md) · [Exceptions](docs/reference/exceptions.md) |
| Advanced | [Sources](docs/advanced/sources.md) · [Hooks and plugins](docs/advanced/hooks-and-plugins.md) · [Performance](docs/advanced/performance.md) · [Operations](docs/advanced/operations.md) |
| More | [Upgrading](docs/upgrade.md) · [Comparison with Spatie, Bouncer, Laratrust, Casbin](docs/comparison.md) |

## Packages

| Package | Contents |
|---|---|
| [`axiomasoft/azguard`](packages/core) | The core: panels, sources, decisions, storage, Laravel integration, CLI, testing kit |
| [`axiomasoft/azguard-filament`](packages/filament) | Filament 5 plugin: resource/page/widget authorization, list filtering, grant editors |

This repository is the development monorepo. The packages are split into read-only repositories on release.

## Requirements

PHP 8.3–8.5 (8.5 with Laravel 13), Laravel 11, 12 or 13, and SQLite, MySQL 8, MariaDB 10.11+ or
PostgreSQL 16. The Filament plugin needs Filament 5.8.4 or later. CI runs every PHP × Laravel pair on SQLite, and
the suite on each database server.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) and [DEVELOPMENT.md](DEVELOPMENT.md). Report vulnerabilities as described
in [SECURITY.md](SECURITY.md), not in public issues.

## License

MIT. See [LICENSE](LICENSE).
