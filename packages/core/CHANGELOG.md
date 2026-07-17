# Changelog

All notable changes to `axioma-studio/azguard-core` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- **Entity-scoped roles are now isolated per panel (F8).** A new migration adds a
  nullable `panel_id` to `model_has_scopes`; assignments persist it and permission
  checks filter by it (`null` = any panel, for back-compat). Previously a scope —
  including a scoped `*` — assigned under one panel was honored in every panel, a
  breach of the core isolation boundary. *Known limitation:* the `HasScopedRoles`
  Eloquent global query-scope is still not panel-aware (it filters by `scope_class`
  regardless of `panel_id`), so scoped *query filtering* can still cross panels even
  though scoped permission *checks* are now isolated — tracked for a follow-up.

### Fixed
- `guard:grants` now queries the `grantable_type` / `grantable_id` columns that the
  `direct_grants` migration actually creates (previously `model_type` / `model_id`,
  so the command returned nothing / errored). The suppressing `phpstan-baseline`
  entries were removed rather than kept. (F1)
- `AbilitiesDto::toArray()` no longer leaks non-boolean subclass properties — it
  returns only the resolved boolean ability map. (F4)
- Enum-based scoped roles now actually grant. `hasScopedPermission` resolves a
  role's declared enum cases (`list<UnitEnum>`, the documented preferred form)
  through the panel — via the single `ClassRoleGrantSource` resolution seam —
  before matching, instead of comparing a resolved string against raw enum cases
  and silently denying. (F3)
- The `AzGuard::grant` / `revoke` / `grants` facade shorthands now default
  `panelId` to `null` and resolve through `config('az-guard.default_panel')`
  (were hardcoded to `'app'`, so an app with a non-`app` default panel wrote
  grants to the wrong panel). `@method` signatures updated to `?string $panelId`. (F6)
- `DirectGrantSource` now honors `features.direct_grants` (disabled ⇒ no grants,
  reads stopped too — not just writes) and the configured `direct_grant` model
  (was hardcoded on the hottest read path). (F17)
- The policy ability-catalog builder no longer crashes application boot on a
  stale/renamed policy class — a `class_exists()` guard skips it and surfaces a
  warning to the log instead of throwing an uncaught `ReflectionException` while
  building the boot-time catalog. Both catalog builders now receive the manager
  via DI, matching the grant-source direction. (F27)
- Dynamic permission definitions (e.g. `app.team.{id}.admin`) are now honored by
  the resolver: `PermissionDefinition::isDynamic()` is read and `{seg}` placeholder
  segments match a single concrete segment, so entity-scoped grants are no longer
  dropped as unknown. Non-dynamic keys still require an exact catalog match. (F28)
- The permission cache key now embeds a per-user epoch that is bumped on
  `forgetForUser`, so a role/grant change invalidates every context-discriminated
  set at once — previously only the base key was dropped, leaving contextual sets
  stale under a persistent store with infinite TTL. A transient context switch uses
  the new in-process-only invalidation (below) and no longer busts the durable,
  cross-request cache. (F30)
- `guard:doctor`'s `checkRoles` no longer casts a backed-enum permission to
  string / uses it as an array key — the documented `list<UnitEnum>` preferred
  form for `BaseRole::permissions()` fatally crashed the command. Enum cases
  are now scoped through the panel, matching `ClassRoleGrantSource`'s own
  resolution. Diagnostics' known-abilities set also now includes the full
  panel `PermissionCatalog` (Filament-discovered resources + plain enum
  cases), so Filament-panel roles are no longer falsely reported as
  referencing an unknown permission; `checkEnumsAgainstPolicies` now only runs
  for panels that actually declare policy classes, so a policy-less
  (Gate/`ResourceGate`) panel is no longer required to have a
  `#[GateAbility]` method per enum case.

### Added
- `AbilitiesDto::make(...)` — the supported way to instantiate an abilities DTO:
  resolves each ability against the Gate and spreads the result into the subclass
  constructor as named arguments. The generated stub was updated to match. (F2)
- `scope_class` is now nullable; logic-less scoped roles store `null` instead of a
  fragile anonymous-class-name sentinel that could not be reliably re-instantiated.
  A new migration relaxes the column. (F48)
- `PermissionResolverInterface::forgetRequestCache()` — in-process-only cache
  invalidation that drops the current request's resolved sets **without** bumping
  the durable per-user epoch; used for transient authorization-context switches. (F30)
- **First-class, symmetric extension surface (Phase 3 — Extension API).**
  - Config-swappable core strategies — `az-guard.manager`, `az-guard.resolver`,
    `az-guard.matcher`, `az-guard.abilities_resolver`. The `AzGuard` facade now
    resolves through `AzGuardManagerInterface`, so a swapped manager reaches every
    `check()` (core call sites route through the interface). (F5)
  - `AzGuard::registerCatalogBuilder()` + `AzGuardManager::CATALOG_BUILDERS_TAG` —
    the public, tagged catalog-builder registration, symmetric with
    `registerGrantSource`. (F7)
  - `AzGuard\Events\AccessDecision` + `Authorizer::explain()` — off-hot-path
    authorization inspection returning a verdict + reason code; the event is
    dispatched only when `features.audit_log` is enabled (default off), which
    makes that previously-unread flag honest. `check()` is untouched. (F16)
  - `PermissionMatcher` contract (config `az-guard.matcher`, memoized) with two
    grammars: the default `WildcardPermissionMatcher` (historical `*` crosses
    dots) and the opt-in `HierarchicalPermissionMatcher` (`*` = one segment,
    `**` = recursive). The 0.3.0 default behaviour is byte-identical; the
    hierarchical grammar becomes the default in 0.4.0. (F21, F22)
  - `AzGuard::abilitiesFor($user, $panel, $keys)` + swappable `AbilitiesResolver`
    (`az-guard.abilities_resolver`) — a curated ability→bool projection for the
    frontend. The `$keys` allowlist is mandatory; the catalog is never dumped. (F37)
  - Opt-in, swappable `RolePermissionValidator`
    (`features.validate_role_permissions`, default off;
    `az-guard.role_permission_validator`) vets role permission keys on save,
    catching stray `*`/typo grants. (F46)
  - Opt-in `az-guard.strict_panels` (default off): resolving an explicit,
    unregistered panel throws `PanelNotFoundException` instead of best-effort
    lenient resolution. (F47)
- **Console role lifecycle (F15).** `guard:role {assign|detach} {user} {role}`
  is the first CLI writer for arbitrary user↔role links (previously only
  `guard:super-admin` could attach, and only the super-admin role). It resolves
  the user by id or email through `ResolvesUserModel`, resolves the role by
  class-name or name through `Config::roleModel()`, and delegates to the existing
  idempotent `HasRoles::assignRole()` / `removeRole()` (which still emit
  `RoleAttached` / `RoleDetached`). Typed against the `HasRoles` contract.
- **Authorization-inspection commands (F53).** Two off-hot-path opt-in commands
  built on `Authorizer::explain()` (F16) and the effective-permission resolver;
  neither touches the hot `check()` path:
  - `guard:explain {user} {ability} [--panel] [--json]` — re-runs a single
    decision and prints the `AccessDecision` (panel, allowed, reason code,
    winning source).
  - `guard:abilities {user} [--panel] [--json]` — prints the user's fully
    resolved `PermissionSet` for a panel (wildcard flag + key list).
- **Structured, CI-parseable command output (F52).** A shared `OutputsStructured`
  concern adds a `--json` flag and a single `{errors, warnings, abilities}`
  payload to the CI-gate commands `guard:doctor` and `guard:catalog:validate`,
  both of which now also return a non-zero exit code on failure.
- **Non-interactive scaffolding (F33).** `make:guard-role` is now argument-driven
  (`make:guard-role app Editor` — no prompt), and `make:guard-panel`,
  `make:guard-permission`, `make:guard-policy` and `make:guard-abilities` accept
  `--force` for idempotent overwrite via the shared `SupportsForcefulGeneration`
  trait. All generators return an `int` exit code.

### Changed
- **CLI commands honor configured models & validate keys (F32).** Eight commands
  (`guard:role-permissions`, `guard:role`, `guard:super-admin`, `guard:sync-roles`,
  `guard:grants`, `guard:prune-grants`, `guard:list-scoped-roles`) hardcoded the
  Role / RolePermission / DirectGrant / ModelHasScope classes instead of routing
  through `Config::*Model()`, so custom model subclasses configured via
  `az-guard.models.*` were silently ignored on the CLI path. A new
  `Config::rolePermissionModel()` (config key `models.role_permission`) and
  `Role::dbPermissions()` back the fix. `guard:role-permissions add|sync` now
  always validates keys against the `PermissionCatalog` and reports an unknown
  key with a non-zero exit code — CLI input validation independent of the opt-in
  `features.validate_role_permissions` model guard (F46).
- **Fixed: `guard:catalog:validate` called the non-existent `AzGuard::getPanel()`
  (F52).** The facade method is `panel()`; the command fatally errored on every
  invocation. The corresponding `phpstan-baseline` entry (a honest-baseline record
  masking the bug) was removed rather than kept.
- **Breaking: unified CLI prefix to `guard:` (F51).** `azguard:install` and
  `azguard:super-admin` are renamed to `guard:install` and `guard:super-admin`.
  The package is pre-1.0 and not yet in production, so the rename ships
  directly — no deprecated alias is kept. Every runtime command now lives
  under `guard:`, and every scaffolding generator under `make:guard-`; update
  any script, scheduler entry, or CI step that calls the old names.
- **Breaking: dropped dead self-referential `$aliases` (F51).** `guard:catalog`,
  `guard:catalog:validate` and `guard:doctor` each declared an `$aliases` entry
  identical to their own primary signature — a no-op that Artisan silently
  ignored. Removed; behavior is unchanged for anyone calling the commands by
  their real name.
- `PermissionCatalog::flush()` is now part of the contract, and panel IDs are
  resolved lazily (no longer frozen at boot) so a panel registered after boot is
  visible via `panels()`. (F40)
- **`@api`/`@internal` SemVer boundary declared (F10).** Every published contract
  (`Contracts/*`, `Registry/Contracts/*`, `Registry/Values/*`, `Support\Panel`,
  `PermissionKey`, the `AzGuard` facade, `Testing/*`) now carries `@api`; resolvers,
  caches, discovery and `RequestState` are `@internal`. A source-level test enforces
  that every contract is `@api` and that no `@api` signature leaks an `@internal` type.
- **All package exceptions now extend `AzGuardException` (F9).** `PanelNotFoundException`,
  `PanelNotSetException`, `InvalidPermissionKeyException`, `InvalidCatalogException` and
  the context package's `MissingAuthorizationContextException` were reparented from
  `RuntimeException`, so a single `catch (AzGuardException)` handles any AzGuard domain
  error. An arch test locks the invariant across every package sub-namespace.
- **Unified enum→string key normalization (F34).** `PermissionKey::normalize(string|UnitEnum)`
  is the single seam behind panel/resolver key coercion (previously reimplemented in four
  places). Behaviour is unchanged.
- Tighter static types: `class-string<…>` on `Config::scopeModel()` / `directGrantModel()`
  (F36); `list<class-string<UnitEnum>>` on `Panel::permissionEnums()` and the enum catalog
  builder, `list<class-string<BaseRole>>` on `Panel::roleClasses()` (F35).
- **Honest static-analysis baseline (F18).** `reportUnmatchedIgnoredErrors` is enabled and
  the two package-wide Builder/Model ignore regexes were removed — `GrantBuilder` is now
  typed `Builder<DirectGrant>`, and the single Filament magic-property site is path-scoped —
  so core `src` is fully type-checked and stale baseline entries were dropped.

### Added
- `AbilitiesDto::make(...)` — the supported way to instantiate an abilities DTO:
  resolves each ability against the Gate and spreads the result into the subclass
  constructor as named arguments. The generated stub was updated to match. (F2)

### Removed
- Dead `Config::cacheKey()` accessor and the `az-guard.cache.key` config entry — the
  value was never read (the cache-key prefix is a fixed internal constant, and Laravel's
  own `cache.prefix` already isolates entries per app on a shared store). (F38)
- **Breaking:** `AzGuard\Registry\Exceptions\InvalidCatalogException` was
  removed. `CompositePermissionCatalog` no longer throws when the same
  permission key is produced by two builders (e.g. Filament resource
  discovery and a permission enum for the same resource) — the key is the
  permission's identity, so it is deduped idempotently instead; a non-null
  `group()` is adopted when the first source had none, so the Role UI still
  groups the permission sensibly. A `catch (InvalidCatalogException)` site
  should remove that arm — the situation it guarded against is no longer an
  error.
- **Breaking:** the unregistered, unreachable `guard:revoke` command
  (`RevokeCommand`, a raw-column duplicate) was deleted. Use the
  production-wired `guard:revoke-grant` (`RevokeGrantCommand`), which revokes
  through `GrantBuilder`. Note: `--all` on `guard:revoke-grant` is scoped to a
  single panel (the required `panel` argument), by design.
- **Dead code — `Guard\PanelManager`, `Grants\PendingGrant`, `Guard\DiscoveryService`
  (F31).** `PanelManager` had zero references and an unpopulated `$panels` collection;
  `PendingGrant` documented a `GrantManager::for()->save()` flow that exists nowhere;
  `DiscoveryService` was exercised only by its own test with a divergent-scanner framing.
  All three were deleted along with their `phpstan-baseline.neon` entries and the
  `DiscoveryTest`. No public API used them. (Invariant held: no `ClassScanner` was
  introduced.)

### Testing & tooling
- **Per-package mutation testing (F50).** `infection.core.json5`,
  `infection.filament.json5` and `infection.context.json5` replace the single root
  config, mirroring the `tests.yml` source layout; `composer mutate:core|filament|context|all|diff`
  and `.github/workflows/mutation.yml` run Infection per package — a diff-scoped
  (`--git-diff-lines`) blocking gate on pull requests plus a full advisory run on
  `main`. Fixed a latent scaffold defect: `testFramework: "pest"` was never a valid
  Infection value (0.34 ships no Pest adapter — only `phpunit`/`phpspec`/`codeception`/`testo`)
  and `logs.summary` must be a file path, not a boolean; both silently broken since
  introduction, now `phpunit` (Pest tests compile to PHPUnit cases) with correct log
  paths. `composer check` gained `check:coverage` and `mutate:all` steps that
  honest-skip with a loud warning (`bin/coverage-gate.sh`, `bin/mutation-gate.sh`)
  when no coverage driver (pcov/xdebug) is present locally — CI always has one, so
  the gate is real where it matters.
- **CLI feature-matrix coverage & `AbilitiesDto` unit suite (F19).** A feature test
  matrix exercises the console command surface end-to-end, closing the previously
  untested CLI paths; a dedicated unit suite pins `AbilitiesDto` (including the F4
  non-boolean-property omission).
- **Contract-parity arch test for the test doubles (F20).** An architecture test
  pins `Testing\FakeAzGuardUser` and `FakeGrantSource` to their production contracts,
  so a contract change that the fakes fail to mirror now fails the suite.
- **Immutability arch ratchets (F49).** Architecture ratchets assert the immutability
  invariants (value objects / DTOs stay `readonly`), locking the boundary against
  regression.

## [0.1.0]

Initial release — code-first, enum/class-first RBAC for Laravel: panels, enum/
class permissions, code roles, direct grants, pluggable GrantSources, permission
cache, and the `azguard:*` / `make:guard-*` console commands.
