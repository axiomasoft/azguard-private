<p align="center"><img src="art/logo.png" width="128" height="128" alt="AzGuard logo"></p>

# AzGuard

[![Tests](https://github.com/axiomasoft/azguard-private/actions/workflows/tests.yml/badge.svg)](https://github.com/axiomasoft/azguard-private/actions/workflows/tests.yml)
[![PHPStan](https://github.com/axiomasoft/azguard-private/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/axiomasoft/azguard-private/actions/workflows/static-analysis.yml)
[![Latest Version](https://img.shields.io/packagist/v/axiomasoft/azguard.svg)](https://packagist.org/packages/axiomasoft/azguard)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Code-first authorization for Laravel 13 with an optional Filament 5 plugin.

- Permissions are backed enums and roles are PHP classes. Grants live in your database.
- Every check goes through one decision pipeline: `hasPermission()`, `@can`, middleware, controller attributes,
  Filament and list queries.
- An error denies, and the decision says whether it failed or was refused. `azguard:explain` shows why.

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

AzGuard is not on Packagist yet. Until the first release, install it from a path or VCS repository
([DEVELOPMENT.md](DEVELOPMENT.md#local-development)); the commands stay the same.

```bash
composer require axiomasoft/azguard
php artisan azguard:install --panel=Admin --migrate
```

Add `HasAzGuard` and `AzGuardSubject` to your `User` model and register a morph alias. Then follow the
**[quick start](docs/getting-started/quick-start.md)**.

The Filament plugin `axiomasoft/azguard-filament` lives in this monorepo and is not published separately; see the
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
| Advanced | [Sources](docs/advanced/sources.md) · [Hooks and plugins](docs/advanced/hooks-and-plugins.md) · [Performance](docs/advanced/performance.md) · [Consistency](docs/advanced/consistency.md) · [Operations](docs/advanced/operations.md) |
| More | [Comparison with Spatie, Bouncer, Laratrust, Casbin](docs/comparison.md) · [Roadmap](ROADMAP.md) |

## Packages

| Package | Contents |
|---|---|
| [`axiomasoft/azguard`](packages/core) | The core: panels, sources, decisions, storage, Laravel integration, CLI, testing kit |
| [`axiomasoft/azguard-filament`](packages/filament) | Filament 5 plugin: resource/page/widget authorization, list filtering, grant editors |

This repository is the development monorepo of both packages.

## Requirements

The 2.0 line supports PHP 8.3–8.5 and Laravel 13.
The Filament plugin requires Filament `^5.8.4` and the exact same AzGuard core version (`self.version`).
CI resolves lowest and latest dependencies for PHP 8.3, 8.4 and 8.5 with Laravel 13 on SQLite. Server lanes use
MySQL 9.7 and PostgreSQL 18, plus Redis 7. MariaDB stays available on the local Compose stand and is not a CI lane.
These lanes do not represent every platform cross-product.
See the [compatibility policy](docs/reference/compatibility.md).

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) and [DEVELOPMENT.md](DEVELOPMENT.md). Report vulnerabilities as described
in [SECURITY.md](SECURITY.md), not in public issues.

## License

MIT. See [LICENSE](LICENSE).
