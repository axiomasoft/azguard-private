# Changelog

All notable changes to `axiomasoft/azguard` and the monorepo's `axiomasoft/azguard-filament` integration (developed under one
version) are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning:
[SemVer](https://semver.org/spec/v2.0.0.html). The section of a version is its GitHub release notes.

## [Unreleased]

Core target: **2.0.0-rc.1**, followed by **2.0.0**. The Filament package is not in publication scope; current work is local to the monorepo. The 1.0.0 entry below is a historical release claim; treat 1.0 as potentially consumed and retain the timestamped inventory snapshot with release evidence as described in [RELEASING.md](RELEASING.md).

### Upgrading to 2.0

- Use `SubjectAccess::onPrimary()` or `AccessRequest::onPrimary()` for sensitive authority reads on replica-default panels. Reload host records from primary and keep `StateRefresh::Check`; coordinate concurrent revokes and host effects when atomicity is required.
- Enforcing Filament panels require `consistency.reads: primary` for writes and exports. The shared export download
  route is refused, including for legacy exports and other panels, because it cannot reconstruct row authorization;
  applications replacing it must reauthorize the actor, original context and every exported record before download.
- Doctor JSON consumers should accept the new `cache.integrity` finding IDs and the changed `cache.signing` severity;
  `cache.store` no longer exposes the store name in its details. Redis isolation requires external verification.
- Permission-set TTL now defaults to 300 seconds; shared entries are capped at 3600 seconds and the nearest expiry.
  Request/job memo maps each retain at most 1024 entries, configurable downwards via `cache.max_memo_entries` and
  `cache.max_state_entries`. See [caching](docs/advanced/caching.md) for targeted cleanup of older entries.
- Shared permission-set caching requires an explicit `AZGUARD_CACHE_NAMESPACE` and a signing secret (the dedicated
  key or Laravel `APP_KEY`); configure bounded previous keys during rotation. Old unsigned entries are ignored and
  reloaded from the authoritative source. See [configuration](docs/reference/configuration.md).
- Source implementations still require only their existing capability interfaces. `SourceDescription` and
  `azguard:panels:list --sources --json` add `volatility` and a declaration-only `matrix`; consumers must not treat
  conditional visibility as an Allow. See [custom sources](docs/guides/custom-sources.md) for the v2 format and doctor findings.
- Upgrade Laravel 11 applications to Laravel 12 or 13 before updating either package.
- Treat `StorageDescriptor::$connection` as a resolved connection name, never null. Consumers that used null to mean
  the current default must use the returned name and explicitly choose a new storage when changing connections.
- Replace direct use of the internal constructors and methods listed below with supported factories and extension
  contracts; use `DoctorContext::storages()` as a list of descriptors instead of relying on its former shape.
- Rename custom participant table base names to lowercase ASCII names matching `[a-z][a-z0-9_]*`, and update any
  schema and queries that refer to them. Set audit retention to at least one day.
- Check wrapper calls that relied on a separately reloaded model: construct the wrapper from the intended current model.
  Give relation editors the owner resource's `update` ability or configure `$azguardRelationAbility`; register widget
  keys or provide a deciding trait before mounting widgets in an enforcing panel.
- Regenerate API manifests with `composer api:manifest` and update tools consuming them for return-by-reference and
  `@phpstan-type` records. Update command snapshot consumers for option mode and repeatability; review machine-format
  fixtures and consumers against the 2.0 baseline before replacing that baseline through the release process.

### Added

- **core:** Machine-readable source capability matrix, with conditional semantics and doctor warnings for declared
  visibility and stable-cache limitations, without a new mandatory source method.
- **core:** Automatic roles can opt into exact visibility with `SelectsAutomaticRoleScopes`, an additive SPI requiring a
  complete finite scope selection. Existing roles remain valid and unsupported exact selections still fail closed.
- **core:** Source contract tests offer an optional mutation fixture for checking fenced grant and revoke state, including
  no-op writes and raw authority, and a finite-universe fixture for scalar, batch and visibility parity.
- **core:** A list field in its own JSON column (`Field::multiple()` without `inMeta()`), stored as JSON text and compared as a list.
- **core:** `FieldSchema::$required`, `$multiple`, `$valueClass`; `PermissionManager::create(description:)`;
  `RelationSource::make(name:)`, so two sources of one scope type can share a panel.
- **docs:** Security and trust model with enforcement locations, proof obligations, and explicit limits
  for cache integrity, actor attribution, replica freshness and check/use atomicity, plus operator-owned restore,
  cache signing rotation and monitoring acceptance runbooks.

### Changed

- **core, filament:** Laravel 11 is no longer supported; the packages require Laravel 12 or 13 (`illuminate/*` `^12.0|^13.0`)
  and PHP 8.3–8.5, with Filament `^5.8.4`.
- **core:** `RelationSource` now rereads host membership for each check, so membership revoked during a request is not reused.
- **core:** `azguard:doctor` reports a missing `catalog.build_id` in production as an error (not a warning) when the
  catalog cache file exists or a panel uses a persistent cache store; the check key and finding id are unchanged.
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
- **filament:** Export authorization follows the export action class when renamed; custom callbacks named `export`
  retain their own action permission. Bulk and export preflight checks finish bounded record queries before nested
  authorization queries so they work with unbuffered MySQL and MariaDB connections.
- **api:** The manifest records return-by-reference and `@phpstan-type` definitions, and the commands snapshot records
  each option's mode and repeatability; auto compatibility comparison stays within major 2, refuses a HEAD baseline and
  verifies the generated migration inventory.
- **ci:** Remove mutation testing workflows, commands and release gate by maintainer decision; qualification preflight,
  worker memory checks, load bench comparator and release artifact/SBOM verification are hardened.

### Fixed

- **core:** Restore signed array-cache reads on Laravel 12.0 while rejecting malformed or unsigned values before deserialization.
- **core:** Shared permission-set reads retain reconstructed factory values, detaching serialized decision-field
  references before placing contributions in request memo storage.
- **core:** Exact visibility for resources without a scope mapping ignores concrete assignments, including the `global`
  scope alias, while retaining tenant-wide grants and the usual validation for mapped resources.
- **core:** `visibleTo()` no longer lists records that `decide()` refuses: a restriction or policy that reads
  `EvaluationContext::matchingGrants()` makes the query throw `VisibilityNotSupportedException` instead of seeing an
  empty list, and a custom `resolve()` is checked even when the scope has no tenant owner.
- **core:** `decideMany()` no longer shares a verdict between grant fields `1` and `1.0`, and accepts binary strings in fields.
- **core:** The directory of a queryable scope offers only contexts its `resolve()` accepts.
- **filament:** Custom bulk actions reject inconsistent select-all, explicit-key and non-scalar deselected-key state before invoking callbacks.
- **filament:** `ViewRole` checks `view` again on every refresh; the generator imports two classes of one basename under
  different names and refuses a result PHP cannot parse; grant pages read each subject label once.

### Security

- **core:** Permission-set cache reads verify a signed HMAC envelope (`SignedEnvelope` v2) before payload decoding and avoid
  cache-driver object deserialization. Shared caching bypasses unsupported stores and Redis connections with client-side
  serialization or compression enabled, includes the signing namespace in keys, and removes invalid entries only when
  their bytes still match the rejected value.
- **core:** Doctor (`cache.integrity`) reports missing namespaces/keys and configured Redis client serialization or
  compression that disable shared permission sets, following Laravel's Redis URL and cluster option precedence.
- **policy:** Set the maintainer email as the canonical private security contact while public advisory reporting is unverified;
  add public Composer security-policy links, coordinated disclosure, and release/signing/cache key compromise guidance.

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
