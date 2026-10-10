# Changelog

All notable changes to `axiomasoft/azguard` and `axiomasoft/azguard-filament` (released together under one
version) are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning:
[SemVer](https://semver.org/spec/v2.0.0.html). The section of a version is its GitHub release notes.

## [Unreleased]

The first public release. Earlier 0.x versions were never published; moving data from 0.3 is described in
[UPGRADING.md](UPGRADING.md). Requires PHP 8.3–8.5 and Laravel 11–13; the Filament package requires Filament 5.

### Fixed

- **core:** Completed and failed decision batches release pinned database handles immediately, including when
  observers retain evaluation contexts or PHP cycle collection is disabled.
- **core:** Batch authorization preserves each supplied subject model and qualifies scope access against that
  instance, even when adjacent requests use the same primary key or a database fallback.

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
  500; a larger set throws `DecisionSetTooLargeException`, never split silently); cached sets are keyed by subject revision and epoch (storage
  schema 2: `azg_subject_revisions`, `azg_panel_state.epoch`), so writes to other subjects keep them. Pre-release
  storages at schema 1 are upgraded by the bundled migration. See the Consistency page of the docs.
- **core:** Failure taxonomy (`FailureKind`, `Decision::failure()`, `DecisionSet::failure()` and `failures()`) and the opt-in
  `DecisionResponder` HTTP mapping (403 deny, 503 transient, 500 contract); doctor check `storage.sqlite`.
- **filament:** `AzGuardPlugin` for Filament 5: resources, relation managers, pages, widgets, bulk actions and
  exports are authorized by AzGuard; lists are filtered in SQL; `enforce()` refuses unprotected surfaces.
- **filament:** Read-only role, panel and doctor pages; editors for role grants, permission grants and runtime
  permissions; a permission generator `azguard:filament:generate`.
