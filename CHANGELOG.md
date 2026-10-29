# Changelog

All notable changes to `axiomasoft/azguard` and `axiomasoft/azguard-filament` (released together under one
version) are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning:
[SemVer](https://semver.org/spec/v2.0.0.html). The section of a version is its GitHub release notes.

## [Unreleased]

### Changed

- **core:** `azguard:doctor` reports a missing `catalog.build_id` in production as an error (not a warning) when the
  catalog cache file exists or a panel uses a persistent cache store; the check key and finding id are unchanged.
- **core, filament:** Laravel 11 is no longer supported; the packages require Laravel 12 or 13 (`illuminate/*` `^12.0|^13.0`).
  The Laravel 11 fallbacks of the contract kit and the optimize registration are removed.
- **core:** `StorageDescriptor::$connection` is the name of the connection the storage resolved to (`string`, no longer
  `?string`); a later change of the default connection no longer moves Audit prune, migrations or doctor.
- **core:** `DoctorContext::storages()` returns `list<StorageDescriptor>`; `DoctorContext::config()` and the
  constructors of `Visibility`, `PanelOverview`, `GateBridge`, `StorageSchema`, `FolderSource::configured()/bind()`,
  `FakeSubject::newEloquentBuilder()` and `AzGuardPlugin::keys()` are internal.
- **core:** A participant's `tables()` are lowercase ASCII base names (`[a-z][a-z0-9_]*`); `ROLE_GRANTS` and aliases are refused at compile time.
- **core:** `AuditOptions` refuses a retention shorter than one day.
- **core:** `isSuperAdmin()`, `hasRole()`, `roleNames()`, `permissionSet()` and panel admission use the model the wrapper
  was built from, as `decide()` does; a bare `SubjectRef` is still read from the source.
- **filament:** Attaching, associating, detaching and dissociating (also in bulk) need `update` of the owner resource,
  or the ability the relation manager declares with `$azguardRelationAbility`; reading alone no longer changes a relation.
- **filament:** The widgets of a resource are keys of the panel, and in a panel that enforces a widget that no key
  names and no trait decides is refused with 403 when it is mounted.
- **api:** The manifest records return-by-reference and `@phpstan-type` definitions, and the commands snapshot records
  each option's mode and repeatability; the compatibility baseline must be replaced for these.

### Fixed

- **core:** `visibleTo()` no longer lists records that `decide()` refuses: a restriction or policy that reads
  `EvaluationContext::matchingGrants()` makes the query throw `VisibilityNotSupportedException` instead of seeing an
  empty list, and a custom `resolve()` is checked even when the scope has no tenant owner.
- **core:** `decideMany()` no longer shares a verdict between grant fields `1` and `1.0`, and accepts binary strings in fields.
- **core:** The directory of a queryable scope offers only contexts its `resolve()` accepts.
- **filament:** `ViewRole` checks `view` again on every refresh; the generator imports two classes of one basename under
  different names and refuses a result PHP cannot parse; grant pages read each subject label once.

### Added

- **core:** A list field in its own JSON column (`Field::multiple()` without `inMeta()`), stored as JSON text and compared as a list.
- **core:** `FieldSchema::$required`, `$multiple`, `$valueClass`; `PermissionManager::create(description:)`;
  `RelationSource::make(name:)`, so two sources of one scope type can share a panel.

## [1.0.0] - 2026-10-14

The first public release of `axiomasoft/azguard` and `axiomasoft/azguard-filament`. Requires PHP 8.3–8.5 and Laravel 12–13; the Filament package requires Filament 5.

### Stability

The API, the extension contracts and the machine formats (configuration, commands, events, exceptions, doctor and explain JSON, publish tags, migrations and tables) follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html). What is supported and what counts as a break is on [docs/reference/compatibility.md](docs/reference/compatibility.md); every identity is listed in [docs/reference/formats.md](docs/reference/formats.md).

### Added

- **core:** Panels: isolated permission sets with their own subjects, roles, sources and settings
  (`PanelProvider`, `PanelBuilder`), and one rule that selects the panel of a check.
- **core:** Permissions as backed enums with authority modes `#[RequiresGrant]`, `#[PolicyOnly]` and
  `#[GrantedToAll]`; roles as classes with stable keys; wildcard grants (`posts.*`, `posts.**`).
- **core:** One decision pipeline for every entry point: before hooks, sources, super admin, policy, authority,
  restrictions, conditions and after hooks. Any error denies; `explain()` and `azguard:explain` show each stage.
- **core:** Sources: `DatabaseSource`, `FolderSource`, `RelationSource`, `GateSource` and custom sources
  (`#[AsSource]`, capability interfaces, health checks, query filtering).
- **core:** Grants with expiry, custom fields, tenants and assignment scopes; change pipes; events published after
  commit with actor and reason; runtime (dynamic) permissions; pruning.
- **core:** `visibleTo()` filters an Eloquent query to the records a subject may see, in SQL.
- **core:** `AzGuard\Support\ModelKey` finds a model by the id of a reference only by its exact key (`01` and
  `a/b` find nothing and never reach the database), as AzGuard resolves subjects, tenants and contexts.
- **core:** Laravel integration: Gate, middleware `azguard.panel` / `azguard.can`, controller attributes, Blade,
  queues (the panel travels in Laravel Context), Octane-safe request state, `optimize` and `about` hooks.
- **core:** Commands: `install`, generators, `doctor`, `explain`, grant and role management, catalog cache, state reset.
- **core:** Testing kit: `InteractsWithAzGuard` (`actingAsWithRoles()`, `actingAsWithPermissions()`),
  `AzGuard::fake()`, contract tests for source and plugin authors.
- **core:** Plugins, and an audit journal plugin (`AuditPlugin`).
- **core:** Read consistency: database sources read state and grants in one read-only snapshot; a `DecisionSet`
  reads in one snapshot per storage connection (at most `decision_sets.max_subjects` distinct subjects, default
  500; a larger set throws `DecisionSetTooLargeException`, never split silently); cached sets are keyed by subject revision and epoch
  (`azg_subject_revisions`, `azg_panel_state.epoch`), so writes to other subjects keep them. See the Consistency page of the docs.
- **core:** Failure taxonomy (`FailureKind`, `Decision::failure()`, `DecisionSet::failure()` and `failures()`) and the opt-in
  `DecisionResponder` HTTP mapping (403 deny, 503 transient, 500 contract); doctor check `storage.sqlite`.
- **filament:** `AzGuardPlugin` for Filament 5: resources, relation managers, pages, widgets, bulk actions and
  exports are authorized by AzGuard; lists are filtered in SQL; `enforce()` refuses unprotected surfaces.
- **filament:** Read-only role, panel and doctor pages; editors for role grants, permission grants and runtime
  permissions; a permission generator `azguard:filament:generate`.

[Unreleased]: https://github.com/axiomasoft/azguard-private/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/axiomasoft/azguard-private/releases/tag/v1.0.0
