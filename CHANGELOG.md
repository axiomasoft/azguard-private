# Changelog

All notable changes to AzGuard are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Per-package mutation testing** — `infection.core.json5`, `infection.filament.json5`,
  `infection.context.json5` replace the single root config; `composer mutate:core|filament|context|all|diff`
  and `.github/workflows/mutation.yml` now run Infection per package, with a
  diff-scoped (`--git-diff-lines`) blocking gate on pull requests and a full
  advisory run on `main`. Fixed a latent config defect from the initial CI
  scaffold: `testFramework: "pest"` was never a valid Infection value (Infection
  0.34 has no Pest adapter — only `phpunit`/`phpspec`/`codeception`/`testo`), and
  `logs.summary` must be a file path, not a boolean; both were silently broken
  since the config's introduction and are now `phpunit` (Pest tests compile to
  PHPUnit test cases, so this runs the existing suite unchanged) with correct log
  paths. `composer check` gained `check:coverage` and `mutate:all` steps that
  honest-skip with a loud warning (`bin/coverage-gate.sh`, `bin/mutation-gate.sh`)
  when no coverage driver (pcov/xdebug) is available locally — CI always has one,
  so the gate is real where it matters. (F50)

### Removed

- **Dead code** — `Guard\PanelManager` (zero references, unpopulated `$panels`
  collection), `Grants\PendingGrant` (documented a `GrantManager::for()->save()`
  flow that exists nowhere), and `Guard\DiscoveryService` (exercised only by its
  own test, divergent-scanner framing) have been deleted along with their
  `phpstan-baseline.neon` entries and the `DiscoveryTest`. No public API used
  these classes. (F31)

## [0.2.0]

Integration-polish & flexibility pass. Sharpens the consumer-facing surface for
downstream integration. Breaking, but mechanical to adopt — see `UPGRADING.md`.

### Added

- **Public actor contracts** — `AzGuard\Contracts\AzGuardUser` (composite, extends
  Laravel's `Authorizable`) plus segregated `HasPermissions`, `HasRoles`,
  `HasScopedRoles`, `HasDirectGrants` interfaces mirroring the `Concerns\*` traits
  1:1. Declare `implements AzGuardUser` on your User model and type-hint it
  everywhere instead of a trait. An arch test pins trait⇄contract signature parity.
- **`isSuperAdmin(?string $panelId = null)`** — first-class super-admin check on
  the user (via `HasPermissions`), the `AzGuard` facade and `AzGuardManager`. No
  more hardcoding the `'*'` wildcard convention. See the `Gate::before` recipe.
- **`AzGuard\PermissionKey`** — public `WILDCARD` (`'*'`) and `SEPARATOR` (`'.'`)
  constants formalizing the `{panel}.{resource}.{action}` grammar; re-exported as
  `PermissionSet::WILDCARD`. Replaces the magic `'*'` literal across the codebase.
- **`hasContextGuard(): bool`** — on the user, facade and manager; plus a
  once-per-request (Octane-safe) debug warning when `hasPermissionIn()` is called
  with no `ContextGuard` bound, so the silent `false` fallback is observable.
- **`AzGuardManager::panelIdForPermission(UnitEnum)`** — resolve the panel that
  owns a permission enum. `hasScopedPermission()` now uses it so an enum with no
  explicit `$panelId` resolves to its own panel, not the default.
- **`AzGuard\Testing\FakeAzGuardUser`** — a database-free user double implementing
  `HasPermissions` for testing integrations without panels/migrations/catalog.
- **`GrantBuilder::expiresAt(?DateTimeInterface)`** — absolute-timestamp
  counterpart to `ttl(int $seconds)`, for parity with `HasDirectGrants::grant()`.
- **`PanelProvider::registerCustomCatalogBuilders(Panel)`** — extension hook to add
  catalog builders without re-implementing the default enum/policy registration.

### Changed

- **BREAKING** `HasDirectGrants::hasGrant($permission, $panelId = null)` now resolves
  the default panel (`az-guard.default_panel`, else `'app'`) when `$panelId` is
  null, and always scopes the query to that panel — consistent with
  `hasPermission()`. Previously it matched a raw key across any panel.
- **BREAKING** `AzGuardManagerInterface` gained `isSuperAdmin`, `hasContextGuard`
  and `panelIdForPermission` — a break only for third-party manager reimplementations.
- **`Config::morphType()`** now throws `InvalidMorphTypeException` on an unknown
  value (validated at service-provider boot) instead of silently falling back to
  `'int'`. Prevents integer morph columns under a ULID/UUID host.
- All source comments, docblocks and default UI strings are now English.

### Fixed

- Unknown permission keys dropped by the catalog filter are logged at debug level
  (typo-catcher for role `permissions()`), and an explicit unregistered panel is
  flagged at debug level under `app.debug`.

## [0.1.0]

Initial public release — code-first, enum/class-first RBAC for Laravel.

### Added

- **Panels** — isolated authorization scopes, scaffolded with `make:guard-panel`
  (auto-registers the panel and wires its permission enum).
- **Enum/class-first API** — permissions as backed enums or `Permission` classes;
  roles as PHP classes declaring enum-case permissions; `assignRole(Role::class)`,
  `hasRole(Role::class)`, `hasPermission(Permission::Case)`, and `string|BackedEnum`
  panel identifiers.
- **Direct grants** — per-user permissions with an optional TTL.
- **Pluggable GrantSources** and a per-request/cross-request permission cache.
- **Console** — `azguard:install`, `azguard:super-admin`, `guard:doctor`,
  `guard:sync-roles`, plus `php artisan about` integration.
- **`axioma-studio/azguard-filament`** — Filament admin UI for roles and grants.
- **`axioma-studio/azguard-context`** — multi-workspace / multi-site authorization
  context (opt-in).
