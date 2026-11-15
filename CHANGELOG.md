# Changelog

All notable changes to `axiomasoft/azguard` and the monorepo's `axiomasoft/azguard-filament` integration (developed under one
version) are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning:
[SemVer](https://semver.org/spec/v2.0.0.html). The section of a version is its GitHub release notes.

## [Unreleased]

The first release line: **2.0.0-rc.1**, then **2.0.0**. Requires PHP 8.3–8.5 and Laravel 13; the Filament package
requires Filament `^5.8.4` and the same core version. The API, the extension contracts and the machine formats
(configuration, commands, events, exceptions, doctor and explain JSON, publish tags, migrations and tables) are
covered by the [compatibility policy](docs/reference/compatibility.md) from the first stable tag.

### Added

- **core:** Panels: isolated permission sets with their own subjects, roles, sources and settings
  (`PanelProvider`, `PanelBuilder`), and one rule that selects the panel of a check.
- **core:** Permissions as backed enums with authority modes `#[RequiresGrant]`, `#[PolicyOnly]` and
  `#[GrantedToAll]`; roles as classes with stable keys; wildcard grants (`posts.*`, `posts.**`).
- **core:** One decision pipeline for every entry point: before hooks, sources, super admin, policy, authority,
  restrictions, conditions and after hooks. Any error denies; `explain()` and `azguard:explain` show each stage.
- **core:** Sources: `DatabaseSource`, `FolderSource`, `RelationSource`, `GateSource` and custom sources
  (`#[AsSource]`, capability interfaces, health checks, query filtering), with a machine-readable capability matrix.
- **core:** Grants with expiry, custom fields (`Field::multiple()` lists in their own JSON column), tenants and
  assignment scopes; change pipes; events published after commit with actor and reason; runtime permissions; pruning.
- **core:** `visibleTo()` filters an Eloquent query to the records a subject may see, in SQL, and throws
  `VisibilityNotSupportedException` instead of listing records that `decide()` would refuse.
- **core:** Laravel integration: Gate, middleware `azguard.panel` / `azguard.can`, controller attributes, Blade,
  queues (the panel travels in Laravel Context), Octane-safe request state, `optimize` and `about` hooks.
- **core:** Commands: `install`, generators, `doctor`, `explain`, grant and role management, catalog cache, state reset.
- **core:** Testing kit: `InteractsWithAzGuard` (`actingAsWithRoles()`, `actingAsWithPermissions()`),
  `AzGuard::fake()`, contract tests for source and plugin authors.
- **core:** Optional modules in their own namespaces: the Scopes plugin, the Audit journal and DevTools.
- **core:** Read consistency: database sources read state and grants in one read-only snapshot; a `DecisionSet` reads
  in one snapshot per storage connection; cached sets are keyed by subject revision and panel epoch. Sensitive checks
  on replica-default panels can read from the primary (`SubjectAccess::onPrimary()`, `AccessRequest::onPrimary()`).
- **core:** Failure taxonomy (`FailureKind`, `Decision::failure()`, `DecisionSet::failures()`) and the opt-in
  `DecisionResponder` HTTP mapping (403 deny, 503 transient, 500 contract).
- **filament:** `AzGuardPlugin` for Filament 5: resources, relation managers, pages, widgets, bulk actions and
  exports are authorized by AzGuard; lists are filtered in SQL; `enforce()` refuses unprotected surfaces.
- **filament:** Read-only role, panel and doctor pages; editors for role grants, permission grants and runtime
  permissions; a permission generator `azguard:filament:generate`.
- **docs:** Security and trust model with enforcement locations and explicit limits for cache integrity, actor
  attribution, replica freshness and check/use atomicity, plus restore, key rotation and monitoring runbooks.

### Security

- **core:** Permission-set cache reads verify a signed HMAC envelope before payload decoding and never deserialize
  cache-driver objects. Shared caching needs an explicit namespace and signing secret, bypasses unsupported stores and
  Redis connections with client-side serialization or compression, and `azguard:doctor` (`cache.integrity`) reports
  each of these conditions.
- **policy:** The maintainer email is the private security contact; the policy covers coordinated disclosure and
  release, signing and cache key compromise.

[Unreleased]: https://github.com/axiomasoft/azguard-private/commits/main
