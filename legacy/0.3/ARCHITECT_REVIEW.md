# AzGuard — Architect Review (0.2.0 → next)

## 1. Executive Summary

AzGuard is, as of 0.2.0, a genuinely differentiated Laravel authorization package with a coherent thesis: **code-first, panel-centric RBAC** where a permission catalog can be a real `UnitEnum`, policies are auto-discovered, and bounded "panels" isolate authorization contexts. The 0.2.0 integration audit resolved the right first-tier issues (public `AzGuardUser` contract, `isSuperAdmin`, `PermissionKey::WILDCARD`, `FakeAzGuardUser`, `morphType` validation). The engine core — immutable `PermissionSet`, priority-ordered `GrantSource` union, Octane-scoped `PermissionCache`, catalog conflict detection — is architecturally sound and, in several respects, ahead of the incumbents.

**Where it stands vs competitors.** Against spatie/laravel-permission it wins decisively on refactor-safety (enums, not strings), multi-panel isolation, and policy autodiscovery. Against bezhanSalleh/filament-shield it wins on the code-first catalog and no-DB-round-trip definitions. Against bouncer/laratrust it *lacks* ownership primitives and negative/forbid grants (a deliberate simplicity choice, but a real modeling gap). Against the FGA/PDP frontier (OpenFGA/Cerbos/SpiceDB) it lacks per-object list filtering, decision explainability, and conditions — none of which it should absorb into core, but all of which it should be *seam-ready* for.

**The honest problems.** Three things undercut the package's own stated tenets right now:

1. **Shipped functional breakage.** `guard:grants` (registered) and `guard:revoke` (dead) query non-existent columns `model_type`/`model_id` — the entire grant-inspection CLI returns nothing or SQL-errors, and the bug was baselined in PHPStan rather than fixed. The `AbilitiesDto` — the object backing the "pass abilities to the frontend" tenet — is **non-instantiable** via the machinery it ships (`resolveFlags()` has zero callers; the generated stub has only a positional ctor).
2. **The tenets are not machine-enforced.** "Swappable default processing class via config" is false for the two most central classes (resolver, manager). The `@api/@internal` boundary is undefined (one symbol each). The catalog-builder extension seam is a bare magic string while its twin (`GrantSource`) is a first-class constant + facade method.
3. **The differentiators are the least-tested surface.** The frontend-abilities tenet has zero test coverage; 15 of ~20 CLI commands have no CLI-level test; the Filament codegen silently diverges from the runtime dialect under any non-snake case config.

**The 5 highest-leverage improvements** (detailed in §3–§4):

| # | Improvement | Why it's top leverage |
|---|---|---|
| A | **Fix the shipped bugs**: `guard:grants`/`guard:revoke` columns, `AbilitiesDto::make()` factory, `toArray()` leak | Active defects on advertised surfaces; cheap; a foundation package cannot ship these |
| B | **Land the extension-API surface**: config-swappable resolver/manager, `registerCatalogBuilder()` + `CATALOG_BUILDERS_TAG`, an opt-in `AccessDecision` event/audit hook | The maintainer's #1 priority; converts stated tenets into real, symmetric, documented seams |
| C | **Declare & enforce the `@api/@internal` boundary** + reparent exceptions to `AzGuardException` | The core SemVer contract for a depended-on package; PHPStan-guardable; near-zero runtime cost |
| D | **Correctness residuals**: enum-based scoped roles silently deny; scoped roles leak across panels | Silent false-negatives/positives on the *idiomatic* path and the *core isolation boundary* |
| E | **Fix the frontend-abilities story end-to-end** + reconcile the two contradictory docs | An explicit tenet and a real ecosystem gap AzGuard's enum-first design is uniquely positioned to own |

---

## 2. Competitive Positioning

| Axis | AzGuard 0.2.0 | spatie/permission v8 | bouncer | laratrust | filament-shield |
|---|---|---|---|---|---|
| **Code-first (enums, not strings)** | ✅ enum catalogs, refactor-safe | ❌ DB strings (enums at boundary only) | ❌ | ❌ | ❌ (wraps spatie) |
| **Panels / bounded contexts** | ✅ first-class | ❌ | scopes (tenant) | teams | per Filament panel |
| **Scoped roles (entity-level)** | ✅ `ModelHasScope` (tuple-lite) | ❌ | ✅ ownership+scopes | ✅ `owns()`/`Ownable` | ❌ |
| **Ownership primitive** | ⚠️ scoped-roles only, no shorthand | ❌ | ✅ `ownedVia()` | ✅ `ownerKey()` | ❌ |
| **Negative/forbid grants** | ❌ (union-only by design) | ❌ | ✅ forbid-precedence | ❌ | ❌ |
| **Catalog engine (multi-source)** | ✅ enum+policy+DB+class, conflict detection | ❌ | ❌ | ❌ | generates into spatie |
| **Policy autodiscovery** | ✅ `#[GateAbility]` + PolicyDiscovery | ❌ | ❌ | ❌ | scan-based |
| **Frontend abilities** | ⚠️ `AbilitiesDto` exists but non-functional | ❌ (none) | ❌ | ❌ | ❌ |
| **Per-object list filtering** | ❌ (Scope apply exists, not promoted) | ❌ | partial | query scopes | ❌ |
| **Extensibility (config-swappable)** | ⚠️ models yes; resolver/manager no | ✅ models + registrar | contracts | contracts | identifier closure |
| **Console coverage** | ⚠️ broad but buggy/undocumented | modest | modest | modest | `shield:generate` |
| **DX / docs** | ⚠️ strong EN, stale RU, drift | ✅ mature | good | good | good |
| **Decision explainability / audit** | ❌ (bool/null only) | 4 mutation events | cache events | — | — |

**What to borrow (without betraying the philosophy):**
- **Ownership as a swappable `OwnershipResolver`** (from Laratrust `Ownable::ownerKey()` / Bouncer `ownedVia()`) — a config-swappable default class, not hardcoded `$user->id === $model->owner_id`.
- **Filament-shield's per-component ergonomics**: configurable permission-identifier closure, `HasAzGuardPage`/`HasAzGuardWidget` traits, `before/after` super-admin gate mode.
- **spatie's swappable wildcard matcher** and **4 mutation events** for auditability (AzGuard already has grant/role events — add the *decision* event).
- **OpenFGA's shapes** (`ListObjects`, `BatchCheck`) as *documented interop recipes* and an optional grant-source, not core.

**What to keep distinct:**
- Union-only grant semantics (priority = ordering, never deny-precedence) — a deliberate simplicity win; do not adopt Cedar-style forbid-override as the model center.
- In-process PDP with pluggable data adapters (like Oso/Cerbos-embedded), never an external service dependency.
- Enum-first catalog — the one thing string-based competitors structurally cannot do; lean into it for typed frontend key export.

---

## 3. Prioritized Roadmap

Findings are de-duplicated across dimensions (several appeared 2–3×). IDs are assigned for reference. Sorted by leverage (shipped-breakage → tenet-enforcement → correctness → ergonomics → polish).

| ID | Area | Title | Sev | Eff | Break | One-line action |
|---|---|---|---|---|---|---|
| F1 | CLI/Correctness | `guard:grants`/`guard:revoke` query wrong columns (`model_type`/`model_id`) | critical | S | N | Use `grantable_type`/`grantable_id`; add CLI round-trip test; drop baseline entry |
| F2 | Frontend | `AbilitiesDto::resolveFlags()` orphaned — DTO non-instantiable | critical | S | N | Add `static make(...)` factory wiring `resolveFlags()` → named-arg spread |
| F3 | Correctness | `hasScopedPermission` silently denies every enum-based scoped role | high | M | N | Resolve declared enum cases via panel before `in_array`; add enum scoped-role test |
| F4 | Frontend | `AbilitiesDto::toArray()` leaks all public props via `get_object_vars` | med | S | Y* | `array_filter(get_object_vars, 'is_bool')` or serialize resolved map only |
| F5 | Extensibility | Resolver + manager hardcoded, no config seam; facade returns concrete | high | M | N | Add `az-guard.resolver`/`.manager` keys; repoint facade at interface |
| F6 | Config | Facade Grants API hardcodes `'app'`, bypasses `default_panel` | high | S | N | Default `panelId=null`, route via `PanelResolver::resolveDefault` |
| F7 | Extensibility | No `registerCatalogBuilder()` / `CATALOG_BUILDERS_TAG` — asymmetric with `GrantSource` | high | S | N | Add constant + facade method; replace 3 magic strings |
| F8 | Correctness | Scoped roles carry no `panel_id` — leak across panels | med | M | N | New migration adds nullable `panel_id`; filter in scoped checks (null=any) |
| F9 | Typing/Exceptions | Registry + panel exceptions bypass `AzGuardException` base | high | S | N | Reparent all to `AzGuardException`; arch test; fix `exceptions.md` claim |
| F10 | Typing | `@api/@internal` boundary undefined (1 symbol each) | high | M | N | One-pass sweep; PHPStan rule forbidding `@internal` in `@api` signatures |
| F11 | Filament | `PermissionEnumGenerator` ignores `case`/`key` config — enforcement break | high | M | N | Inject `PermissionSchema`; round-trip test under non-snake case |
| F12 | Filament | Phantom `az-guard.filament.user_label_column` — dead override | high | S | N | Add key to `az-guard-filament.php`; fix 4 read sites |
| F13 | Filament | Page/widget permissions catalogued but un-enforceable | high | M | N | Ship `HasAzGuardPage`/`HasAzGuardWidget` traits or stop emitting + document |
| F14 | Context | No write API + middleware alias not auto-registered | high | M | N | Auto-alias in `boot()`; ship `guard:context:grant/revoke` + builder |
| F15 | CLI | No `guard:role:assign/detach` — role lifecycle unmanageable from console | high | M | N | Add multiplexed command using `ResolvesUserModel` + `Config::roleModel()` |
| F16 | Extensibility | No `AccessDecision`/denial event; `audit_log` flag consumed nowhere | med | M | N | Opt-in `AccessDecision` event from `Authorizer`; make `audit_log` honest |
| F17 | Config | `DirectGrantSource` ignores feature flag + hardcodes model | med | S | N | Gate on `directGrantsEnabled()`; resolve via `Config::directGrantModel()` |
| F18 | Typing | PHPStan baseline hides real bugs; blanket Builder/Model ignores | med | M | N | Fix F1; `reportUnmatchedIgnoredErrors:true`; scope ignores by path |
| F19 | Test | 15/20 CLI commands untested; frontend-abilities tenet 0 tests | high | M | N | Command Feature-test matrix; `AbilitiesDto` unit suite (after F2) |
| F20 | Test | Fakes have no contract-parity arch test | med | S | N | Extend `ContractTraitParityTest` to `FakeAzGuardUser`/`FakeGrantSource` |
| F21 | Extensibility | Wildcard matcher hardcoded + non-swappable + regex recompiled per key | med | M | N | Extract `PermissionMatcher` contract; memoize compiled patterns |
| F22 | Correctness | Wildcard `*`→`.*` crosses dot boundaries | med | M | Y | Document grammar; `*`→`[^.]*`, add recursive `**`; level-crossing tests |
| F23 | Docs | `abilities-frontend.md` teaches non-canonical hand-rolled DTO | high | S | N | Rewrite to `AbilitiesDto::make()->toArray()` (after F2) |
| F24 | Docs | `extending.md` custom-catalog-builder example non-compilable | high | M | N | Use `SimplePermissionDefinition`; show registration via F7 |
| F25 | Docs | `exceptions.md` falsely claims all extend `AzGuardException` | high | S | N | Made true by F9; keep as coupled fix |
| F26 | Config | Context roles table reads non-existent core config key | med | S | N | Add `table_names.context_roles` to `az-guard-context.php`; read there |
| F27 | Architecture | Catalog builders couple to facade; policy builder lacks `class_exists` guard | med | M | N | Add guard (boot-crash fix); inject manager for parity with GrantSources |
| F28 | Architecture | `PermissionDefinition::isDynamic()` unimplemented in resolver | med | M | N | Honor in `filterAgainstCatalog` OR remove from contract |
| F29 | Filament | `DoctorPage` runs `diagnose()` 3×/render; `DirectGrantResource` N+1 | med | S | N | Memoize `runDiagnose()`; batch-resolve user labels |
| F30 | Config/Correctness | Infinite-TTL store leaves context-discriminated sets stale | med | M | N | Per-user epoch key prefix; increment on `forgetForUser` |
| F31 | Architecture | Dead code: `PanelManager`, `PendingGrant` (refs non-existent `GrantManager`) | med | S | N | Delete both; delete/repurpose `DiscoveryService` |
| F32 | CLI | Swappable-model config bypassed CLI-wide; unvalidated permission keys | med | M | N | Route via `Config::*Model()`; validate keys vs catalog on add/sync |
| F33 | CLI | `make:guard-*` no `--force`; `make:guard-role` interactive-only | med | M | N | Add `--force`; make role generator argument-driven + shared trait |
| F34 | Typing | Enum→string normalization duplicated 5+ sites | med | M | N | `PermissionKey::normalize(string\|UnitEnum): string` seam |
| F35 | Typing | Enum-class params typed bare `class-string` | med | S | N | `list<class-string<UnitEnum>>` on Panel/enum builder |
| F36 | Typing | `scopeModel()`/`directGrantModel()` return bare `string` | med | S | N | `class-string<...>` annotations mirroring `roleModel()` |
| F37 | Extensibility | No first-class `AzGuard::abilitiesFor()` projection | med | M | N | Swappable `AbilitiesResolver`; curated key list, never full catalog |
| F38 | Config | Dead `cache.key` config knob | low | S | N | Wire `Config::cacheKey()` into `keyFor()` or delete |
| F39 | Filament | `AzGuardPlugin` default `'app'` ≠ config `'admin'` | med | S | N | Default `panelId` from config in `getPanelId()` |
| F40 | Config | `PermissionCatalog` lacks `flush()`; frozen panel list at boot | low | S | Y | Add `flush()` to interface (0.3.0); lazy panelIds (follow-up) |
| F41 | Filament | Context `DenyWithoutContextStrategy` dead exception + false docblock | low | S | N | Delete `MissingAuthorizationContextException` |
| F42 | Docs | RU mirror 19–59% of EN; `recipes/index.md` Russian in EN tree | med | L | N | Fix leak; backfill integration-surface pages; CI parity check |
| F43 | Docs | Wrong min PHP (docs 8.2, composer ^8.3) | low | S | N | Global replace to 8.3+; CI doc-lint vs composer |
| F44 | Docs | CLI docs cover ~6/21 commands; misstate prefix taxonomy | med | M | N | Generate reference from registered list; CI drift test |
| F45 | Docs | Directory drift: docs `App\AzGuard\` vs generator `App\Guards\` | med | S | N | Standardize on `App\Guards\` (generator = source of truth) |
| F46 | Correctness | Unvalidated `*` DB row = un-linted super-admin | low | M | N | Opt-in swappable `saving()` validator; wildcard respects own panel |
| F47 | Correctness | Unregistered-panel handling diverges (emit-unscoped vs deny vs `'app'`) | low | M | N | Opt-in `strict_panels` config throwing `PanelNotFoundException` |
| F48 | Correctness | `scope_class` stores anonymous-class sentinel for logic-less roles | low | S | N | Nullable `scope_class`; store null; skip explicitly |
| F49 | Test | Arch tests miss own tenets (no final/readonly/traits); no datasets | low | S | N | Add `toBeFinal()->toBeReadonly()` ratchets; parameterize matrices |
| F50 | Test/CI | Mutation core-only + advisory; `composer check` omits coverage/mutation | med | M | N | Per-package infection scope; diff-scoped PR gate; add to `check` |
| F51 | CLI | Self-referential dead `$aliases` on 3 commands; `azguard:`/`guard:` split | med | S | Y* | Delete self-aliases (non-breaking); prefix rename deferred |
| F52 | CLI | `--json`/`--format` absent from doctor/catalog:validate (CI gates) | high | M | N | Shared `OutputsStructured` concern; structured payload + exit code |
| F53 | CLI | No `guard:explain`/`guard:abilities` inspection command | med | M | N | Add off existing resolver/`AbilitiesDto`; enhancement |
| F54 | Governance | `.claude` toolkit drift (BaseRole rector skip, reviewer targets non-existent arch, Boost skill a version behind) | med | S | N | Fix rector skip path, retarget reviewer, update Boost skill to 0.2 API |

\* F4/F51 are *narrowing* changes — technically observable-shape changes, treat as breaking-with-note.

### Quick wins (non-breaking, S)
F1, F2, F6, F7, F9, F12, F17, F20, F25, F26, F29, F31, F35, F36, F38, F39, F41, F43, F45, F48, F49, F51 (alias deletion only), F54.

### 0.3.0 targets
F3, F5, F8, F10, F11, F13, F14, F15, F16, F18, F19, F21, F23, F24, F27, F28, F30, F32, F33, F34, F37, F42, F44, F46, F47, F50, F52, F53.

### 1.0 / breaking (deprecate-first)
F4 (output-shape narrowing), F22 (wildcard grammar), F40 (interface method), F51 (prefix rename if pursued). See §5.

---

## 4. Deep-Dive Sections

### 4.1 Architecture & Structure

**Dead code (F31).** `Guard/PanelManager.php` has zero references and an unpopulated `$panels` collection; `Grants/PendingGrant.php` documents a `GrantManager::for()->save()` flow that exists nowhere (`GrantManager` appears only in that docblock). `DiscoveryService` is exercised only by its own test. All three contradict the low-bloat tenet.
- **Action:** Delete `PanelManager` and `PendingGrant`. Delete `DiscoveryService` + its test, or drop the divergent-scanner framing. **Do not** extract a shared `ClassScanner` seam — three call sites with different predicates is below the Rule of Three and would add surface.

**Catalog builders couple to the facade; boot-crash asymmetry (F27).** `EnumPermissionCatalogBuilder.php:37` and `PolicyAbilityCatalogBuilder.php:37` both call `AzGuard::panel()` statically (unlike GrantSources, which receive `AzGuardManagerInterface`). Worse, `PolicyAbilityCatalogBuilder.php:52` does `new ReflectionClass($policyClass)` with **no** `class_exists` guard, while `EnumPermissionCatalogBuilder.php:52` guards it. A renamed/stale policy class throws an uncaught `ReflectionException` while building the boot-time catalog singleton — taking the app down.
```php
// PolicyAbilityCatalogBuilder::build() — parity fix (high value, low risk)
if (! class_exists($policyClass)) {
    continue; // surface to AzGuardDiagnostics rather than crashing boot
}
$reflection = new ReflectionClass($policyClass);
```
- **Action:** Add the guard first. Optionally inject the manager into both builders to match the DI direction.

**`isDynamic()` is a promise the engine doesn't keep (F28).** `PermissionDefinition::isDynamic()` (`PermissionDefinition.php:41`) is backed by `SimplePermissionDefinition` but read nowhere; `EffectivePermissionResolver::filterAgainstCatalog` has no dynamic branch, so a dynamic grant (`app.team.{id}.admin`) is dropped as unknown — precisely the multi-tenant case AzGuard invites.
- **Action:** Either honor it (after an exact miss, match `{seg}` segments as wildcards) or remove it from the contract + both definitions. Given the entity-scoped ambitions, honoring it is the better seam — but it must not ship half-wired.

### 4.2 Typing & Static Analysis

**`@api/@internal` boundary (F10).** Exactly one `@api` (`PermissionSet`) and one `@internal` (`ScopedRoleCache`) exist. PHPStan 2.0 auto-finals `@api` classes and fires `parameter.internalInterface` when `@internal` types leak into public signatures — none of that protection is active, so no refactor is SemVer-guarded.
```php
/** @api Stable public contract — implement to add a custom grant source. */
interface GrantSource { /* ... */ }

/** @internal */
final class PermissionCache { /* ... */ }
```
- **Action:** One-pass sweep — `@api` on `Contracts/*`, `Registry/Contracts/*`, `Registry/Values/*`, `Support/Panel`, `PermissionKey`, `Facades/AzGuard`, `Testing/*`; `@internal` on resolvers/caches/discovery/`RequestState`. Add a PHPStan rule + arch test asserting every `Contracts/` interface carries `@api`.

**Baseline hides real bugs; blanket ignores (F18).** The `DirectGrant::$model_id` `property.notFound` error (the F1 bug) was *baselined* rather than fixed. Two unscoped `ignoreErrors` regexes suppress **all** undefined-Builder-method / undefined-Model-property errors package-wide, defeating `checkModelProperties:true`.
- **Action:** Fix F1, delete the baseline entry, set `reportUnmatchedIgnoredErrors: true`, and scope the two regexes by `path:` (or prefer `@property` docblocks already present on models). Note: the `RolePermission::$created_at` baseline entry is *not* a functional bug (the migration has `->timestamps()`); it needs a `@property` docblock only — do not over-claim.

**Enum normalization seam (F34) & enum-class typing (F35).** `$p instanceof BackedEnum ? $p->value : $p->name` is reimplemented in `Panel.php:132`, `PermissionName.php:38`, `PanelResolver.php:70`, `CheckAccess.php:103`, plus grant-source branches. `PermissionKey` is a constants-only final class — the natural home.
```php
final class PermissionKey {
    public static function normalize(string|\UnitEnum $p): string {
        return match (true) {
            $p instanceof \BackedEnum => (string) $p->value,
            $p instanceof \UnitEnum  => $p->name,
            default => $p,
        };
    }
}
```
Route the *pure* enum→string sites through it; leave panel-scoping wrappers (`Panel::resolvePermission`) calling `normalize()` then scoping. Separately, type enum-class params as `list<class-string<\UnitEnum>>` (`Panel::permissionEnums`, `EnumPermissionCatalogBuilder`) so `::cases()`/`::from()` are analyzed — `roleClasses` is a *separate* concern (class roles, not enums), type it `list<class-string<BaseRole>>`.

**Model-config accessors (F36).** Mirror `roleModel()`'s `class-string<Role>` pattern on `scopeModel()`/`directGrantModel()` so a typo fails at the config seam, not deep in Eloquent. Optionally add a boot-time `is_subclass_of()` check like the existing `morphType()` fail-fast.

**Do NOT** add `@template-covariant` to `PermissionSet` — permission keys are a flat, non-parametric string space; generics add ceremony with no consumer benefit.

### 4.3 Extensibility & Integration Hooks — *the coherent extension-API surface*

This is the maintainer's top priority. AzGuard has two symmetric "many implementations coexist" seams (`GrantSource`, `CatalogBuilder`) and two "one active strategy" seams (resolver, manager) — today they are wired inconsistently. The proposal below makes the full surface symmetric, config-swappable, and discoverable.

**(1) Config-swappable core processing classes (F5).** `AzGuardServiceProvider.php:73-74` binds the manager concretely; `:123` binds the resolver concretely; the facade (`AzGuard.php:46`) returns the *concrete* `AzGuardManager::class`, so even an interface rebind is bypassed by the facade.
```php
// config/az-guard.php
'manager'  => \AzGuard\AzGuardManager::class,
'resolver' => \AzGuard\Registry\Resolver\EffectivePermissionResolver::class,

// register()
$this->app->singleton(config('az-guard.manager'));
$this->app->bind(AzGuardManagerInterface::class, config('az-guard.manager'));
// resolver: keep the existing SCOPED closure factory, but bind the interface
// to the configured class so the swap actually reaches every check.

// Facades/AzGuard.php
protected static function getFacadeAccessor(): string
{
    return AzGuardManagerInterface::class; // was AzGuardManager::class
}
```
Preserve the resolver's scoped lifecycle (it captures `PermissionCache` per request). **Do not** add a `catalog` key here — the catalog is a boot-time singleton with its own closure.

**(2) First-class catalog-builder registration (F7).** `GrantSource` has `AzGuardManager::GRANT_SOURCES_TAG` + `registerGrantSource()`; catalog builders have a bare `'azguard.catalog_builders'` string duplicated across `PanelProvider.php:99,108` and `AzGuardServiceProvider.php:142`, reachable only via the protected `registerCustomCatalogBuilders()` hook.
```php
public const string CATALOG_BUILDERS_TAG = 'azguard.catalog_builders';

public function registerCatalogBuilder(string $builderClass): void
{
    if (! app()->bound($builderClass)) {
        app()->singleton($builderClass);
    }
    app()->tag([$builderClass], self::CATALOG_BUILDERS_TAG);
}
```
Add the `@method` line to the facade; replace all three literals with the constant; keep `registerCustomCatalogBuilders()` as the panel-scoped convenience.

**(3) Authorization-decision event / audit hook (F16).** `Authorizer::check()` returns `true`/`null` and discards *why*; there is no decision event (auditors' key moment); `Config::auditLogEnabled()` has zero readers (the role events fire regardless of the flag). This is the highest-value observability addition and reuses existing event + config machinery.
```php
final readonly class AccessDecision
{
    public function __construct(
        public int|string $userId,
        public string $panelId,
        public string $ability,
        public bool $allowed,
        public string $reasonCode,       // WILDCARD|SOURCE_GRANT|PATTERN_MATCH|NO_GRANT|NO_ACTIVE_PANEL
        public ?string $winningSource = null,
        public ?string $correlationId = null,
    ) {}
}
```
Dispatch it **only when** `Config::auditLogEnabled()` (default off → low-bloat). Keep the hot `check()` path untouched; drive an opt-in `Authorizer::explain()` (re-runs with a recording flag) and a `guard:explain` command off it. First make the flag honest: either gate role-mutation logging behind it or drop the "log role events" doc claim.

**(4) Adapter contracts for future-proofing.** Introduce (config-swappable, container-resolved) an `OwnershipResolver` (`ownedVia(Model, column|closure)` → `owns($entity)` / `hasPermissionOrOwns()`) and a `PermissionMatcher` (F21) so wildcard/hierarchical matching and OpenFGA/ReBAC backends are swappable without touching consumer-facing checks. These are the BC-friendly seams the research recommends — **implement only the matcher now** (a real second impl is plausible: hierarchical); keep OwnershipResolver as a designed-but-deferred contract.

**(5) Macroable actor / fluent objects.** Add `Macroable` **class-by-class** to user-facing fluent objects (`PanelProvider`, role/ability builders, the abilities DTO) — *not* internal services — so integrators add helper methods without subclassing internals. Document which classes are macroable; do not advertise a framework-wide guarantee.

**(6) `PanelProvider` container-resolvability.** Mirror Filament's `public static function make(): static { return app(static::class); }` and a `configureUsing`-style per-panel self-registration hook, so downstream *packages* can attach panels without editing every host's `config('az-guard.panels')`. This is what makes AzGuard "the foundation for many downstream projects."

### 4.4 Config-Overridability

**Facade `'app'` hardcode (F6) — the strongest finding.** `AzGuardManager::grant/revoke/grants` (`:165,180,193`) default `$panelId = 'app'` and never call `PanelResolver::resolveDefault`, while `isSuperAdmin()` (same class, `:110`) *does*. An integrator with `default_panel='admin'` writes grants to `'app'` via the facade shorthands — data to the wrong panel.
```php
public function grant(
    Authenticatable $user,
    string|UnitEnum $permissionKey,
    string|BackedEnum|null $panelId = null,   // was = 'app'
    ?int $ttl = null,
): DirectGrant {
    $panelId = PanelResolver::resolveDefault(
        $panelId === null ? null : PanelResolver::normalizeId($panelId)
    );
    return $this->forUser($user)->on($panelId)->ttl($ttl)->grant($permissionKey);
}
```
Update the facade `@method` docblocks to `?string $panelId = null`.

**`DirectGrantSource` defeats two knobs (F17).** `DirectGrantSource.php:27` uses `DirectGrant::query()` directly with no `directGrantsEnabled()` gate and hardcodes the model — so `features.direct_grants=false` doesn't stop reads and `config('models.direct_grant')` is ignored on the hottest path.
```php
public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
{
    if (! Config::directGrantsEnabled()) {
        return PermissionSet::empty();
    }
    $model = Config::directGrantModel();
    $keys = $model::query()
        ->where('grantable_type', $user::class)
        ->where('grantable_id', $user->getAuthIdentifier())
        ->where('panel_id', $panelId)->active()
        ->pluck('permission_key')->all();
    return PermissionSet::fromRawKeys($keys);
}
```
Prefer filtering tagged sources by feature flag in the resolver factory (cleaner than an in-method guard).

**Dead knobs.** `cache.key`/`Config::cacheKey()` has zero callers — `keyFor` hardcodes `'azguard.perms.'` and the config default (`'azguard.permissions'`) doesn't even match (F38). Context roles table reads a non-existent *core* key (F26) — move ownership to `az-guard-context.php`.

### 4.5 Console Commands & Machine-Readable Output

**Broken grant inspection (F1).** `GrantsListCommand.php:44,46,49` and `RevokeCommand.php:60-61` filter `model_type`/`model_id`; the schema is `grantable_type`/`grantable_id`. `guard:grants` always returns "No grants found" (or SQL-errors on Postgres/MySQL). `RevokeCommand` (`guard:revoke`) is additionally *unregistered* and duplicates the correct `guard:revoke-grant` (which routes through `GrantBuilder`).
- **Action:** Fix columns in `GrantsListCommand` (via model constants, not raw strings). **Delete** `RevokeCommand` (dead + buggy); if cross-panel `--all`/optional-key ergonomics are wanted, fold them into `guard:revoke-grant`. Add a seed→list→revoke round-trip Feature test.

**Missing role lifecycle (F15).** No `guard:role:assign/detach`. `SuperAdminCommand` is the only user↔role writer and only for `SuperAdminRole`. Add a multiplexed `guard:role {assign|detach} {user} {role}` using `ResolvesUserModel` + `Config::roleModel()`, firing `RoleAttached/RoleDetached` — mirroring `SuperAdminCommand`'s existing `$role->users()->syncWithoutDetaching([...])`.

**Structured output for CI gates (F52).** `guard:doctor` and `guard:catalog:validate` are positioned as CI gates but emit only text. Add a shared `OutputsStructured` concern with `--json` emitting `{errors:[],warnings:[],abilities:[]}` while keeping the non-zero exit code. Reuse `CatalogListCommand`'s RFC-ish CSV escaper; drop `GrantsListCommand`'s naive `csvEscape`.

**Other:** swappable-model config bypassed CLI-wide + unvalidated keys on `role-permissions add/sync` (F32); no `--force`/idempotency on `make:guard-*` and `make:guard-role` is interactive-only (F33); dead self-referential `$aliases` on 3 commands (F51 — delete, non-breaking). The `azguard:`/`guard:` prefix split (F51) is a *breaking* rename — defer, keep old names as real aliases if pursued.

### 4.6 Testing

- **CLI (F19):** only ~5 command signatures are exercised; 15 have zero CLI-level tests — which is why F1 shipped. Add a data-driven Feature matrix (one file per command family) running `$this->artisan()` and asserting exit code **and** a DB/output side-effect. Prioritize the `guard:grants` round-trip and a `catalog:validate --strict` failure case.
- **Frontend abilities (F19):** `AbilitiesDto`/`ResolvesGateAbilities`/`BladeHelper` = 0 references. After F2, assert `make()` resolves flags via a bound `Gate`, `toArray()` returns only the flag map (pins F4), and `make:guard-abilities` generates a compilable, instantiable DTO.
- **Fake parity (F20):** `#[Override]` catches *removal/signature* drift but not *addition* of a new contract method. Extend `ContractTraitParityTest` (reuse its `$signature()` helper) to assert `FakeAzGuardUser` implements every `HasPermissions` method and `FakeGrantSource` its contract.
- **Branch-heavy internals (F19):** `AzGuardDiagnostics`, `PolicyDiscovery`, `PanelResolver::resolveDefault`, `PermissionName`, `RequestState` = 0 direct tests. Use Pest datasets for `(input-type × expected-key)` and `(panel-state × expected-fallback)`.
- **Middleware/context (F19):** `CheckDirectGrant`, `PanelCheckAccess`, `SetAuthorizationContext` = 0 tests; pin the acknowledged infinite-TTL context-staleness gap either way.
- **Arch ratchets (F49):** add `Events + Registry\Values + Abilities` → `toBeFinal()->toBeReadonly()`, `Concerns` → `toBeTraits()`.
- **Gates (F50):** mutation is core-only + advisory; `composer check` omits coverage/mutation (though coverage *does* run in `tests.yml`). Add per-package infection scope + a diff-scoped (`--git-diff-lines`) PR-blocking run; add `@test:coverage` to `check`.

### 4.7 Documentation & DX

| Finding | Fix |
|---|---|
| F25 — `exceptions.md` false "all extend `AzGuardException`" | Made true by F9 |
| F23 — `abilities-frontend.md` non-canonical hand-rolled DTO | Rewrite to `AbilitiesDto::make()->toArray()` (after F2) |
| F24 — `extending.md` custom-builder non-compilable | `SimplePermissionDefinition(key:, panelId:, ...)` + registration via F7 |
| F45 — dir drift `App\AzGuard\` vs `App\Guards\` | Standardize on `App\Guards\` (generator = source of truth) |
| F44 — CLI docs ~6/21, wrong prefix taxonomy | Generate reference from registered list; CI drift test |
| F42 — RU mirror 19–59%, EN `recipes/index.md` is Russian | Fix leak; backfill integration/testing/permissions/direct-grants; CI parity check |
| F43 — docs say PHP 8.2, composer ^8.3 | Global replace to 8.3+; CI doc-lint vs composer |

The recurring theme: **generate docs from code** (command list, exception parentage, PHP constraint) so drift becomes a CI failure, not a review burden.

### 4.8 Frontend Abilities

**The DTO is non-functional (F2 — critical).** `AbilitiesDto::resolveFlags()` (`:22`, protected static) has **zero callers**; the generated stub (`domain-abilities.stub:13-19`) has only a positional ctor; `permissions.md:200` hand-waves `new DocumentsAbilities(/* ...resolved flags... */)`. The abstraction cannot be instantiated by the machinery it ships.
```php
// AbilitiesDto — the missing factory
public static function make(mixed ...$arguments): static
{
    /** @var array<string,bool> $flags keyed by abilityMap keys == ctor param names */
    $flags = static::resolveFlags(array_values($arguments));
    return new static(...$flags); // string-keyed spread => named args (valid PHP 8.1+)
}
```
This works because `resolveFlags()` keys == `abilityMap()` keys == ctor param names.

**The leak (F4).** `toArray()` returns `get_object_vars($this)` — any public field an integrator adds ships to the client.
```php
public function toArray(): array
{
    return array_filter(get_object_vars($this), 'is_bool');
    // better once make() lands: hold the resolved map in a private readonly array
}
```

**Two docs contradict (F23).** `abilities-frontend.md` imports `ResolvesGateAbilities` but never calls it and hardcodes `'app.documents.view'` — the string-brittle anti-pattern AzGuard exists to prevent — while `permissions.md` + the stub teach the enum path. Collapse to the canonical `AbilitiesDto::make()->toArray()` path.

**The missing projection (F37).** There is no `AzGuard::abilitiesFor()` for coarse shared-props (nav/shell); the Inertia recipe hand-builds a nested `hasPermission()` map.
```php
// config: 'abilities' => ['resolver' => DefaultAbilitiesResolver::class]
AzGuard::abilitiesFor($user, panel: 'app', only: [
    'documents.view', 'documents.create',
]); // => ['documents.view'=>true, 'documents.create'=>false]
```
Curate always — **never** dump the catalog; the `$only` allowlist lets shared-props request a narrow subset. Ship a `HandleInertiaRequests` lazy-closure fragment. Reconcile the two docs' tiering guidance: per-**resource** abilities go per-page via `AbilitiesDto`; a coarse whole-app map may be shared globally. A `guard:abilities {user}` inspection command (F53) and an optional `guard:abilities:export` (TS `.d.ts` from enum catalogs) are the enum-first differentiator no string-based competitor can offer — sequence them *after* `abilitiesFor()`.

### 4.9 Correctness & Security Residuals

**Enum scoped roles silently deny (F3 — high).** `HasScopedRoles.php:206-208` compares a *resolved scoped string* against `$logic->permissions()`, which `BaseRole` documents as the *preferred* `list<UnitEnum>`. `in_array('app.projects.edit', [ProjectPermission::Edit], true)` is always false. The idiomatic, refactor-safe pattern grants **zero** scoped permissions; the test suite masks it (every scoped test role uses plain strings).
- **Action:** Delegate to the single source of truth — expose `ClassRoleGrantSource::resolvePermissions` (currently private) as a small public `resolveFor(RoleInterface, string $panelId): list<string>`, or resolve each declared enum case via `panel($panelId)->resolvePermission()` before the `in_array`. Add an **enum-based** scoped-role test.

**Scoped roles leak across panels (F8).** `model_has_scopes` has no `panel_id` (migrations `000000`/`000001`); `assignScopedRole` persists none; `hasScopedPermission` filters none — diverging from `DirectGrant` and `RolePermission`, which both carry `panel_id`. A scope (especially a scoped `*`) assigned under panel A is honored for panel B — a breach of the core isolation boundary.
- **Action:** New migration adds nullable `panel_id` (never edit an applied migration; give it a correct `down()`); persist + filter it (null = any-panel for back-compat); add to `ModelHasScope::$fillable`. Ship as a minor.

**Wildcard crosses dot boundaries (F22).** `PermissionSet.php:112` maps `*`→`.*`; `app.documents.*` matches `app.documents.sub.view`. Mitigated by `features.wildcard_permission=false` default, but a trap when enabled. Document the grammar; map `*`→`[^.]*`, add recursive `**`→`.*`; memoize compiled regex. **Do not** gate this behind a new `PermissionMatcher` class *unless* a second matcher materializes (Rule of Three) — though F21 makes the matcher swappable anyway, keep the default behavior identical.

**Infinite-TTL context staleness (F30).** `forgetForUser` (`PermissionCache.php:66`) drops only the base key; context-discriminated entries rely on TTL. With a persistent store + infinite TTL + context package, a role change never evicts contextual sets.
- **Action:** Per-user epoch integer in the key prefix (`azguard.perms.{uid}.{panel}.v{epoch}[.disc]`), incremented in `forgetForUser` — invalidates every discriminator at once, non-breaking, future-proofs Octane multi-request staleness.

**Lower-severity residuals:** unvalidated `*` DB row = un-linted super-admin (F46 — opt-in swappable `saving()` validator, default lenient); unregistered-panel divergence (F47 — opt-in `strict_panels`); anonymous-class `scope_class` sentinel (F48 — make nullable, store null).

### 4.10 Filament & Context

**Enum generator drifts from runtime (F11 — high).** `PermissionEnumGenerator.php:26` hardcodes `Str::snake()` and can't receive `PermissionSchema`, so it ignores `config('az-guard-filament.case')` — while `PolicyGenerator` and `ResourceGate` both key via `$schema->key()`. Under any non-snake case, generated enum keys silently diverge from the keys the gate enforces → authorization break.
- **Action:** Inject `PermissionSchema` into the enum generator (parity with `PolicyGenerator`); round-trip test under kebab case asserting the generated case value equals `ResourceGate`'s key.

**Phantom config (F12 — high).** `DirectGrantResource.php:54,132` and `RoleUsersRelationManager.php:33,47` read `config('az-guard.filament.user_label_column')` — a namespace that doesn't exist. Always falls back to `'name'`; breaks email-only user models. Add the key to `az-guard-filament.php`; fix all four reads.

**Un-enforceable pages/widgets (F13).** Filament routes custom Pages via static `canAccess()` and Widgets via static `canView()` — neither through the Gate, so `ResourceGate`'s `Gate::before` structurally cannot enforce them; yet AzGuard mints `{panel}.{page}.view` keys into the catalog and shows them in the Role UI. Ship opt-in `HasAzGuardPage`/`HasAzGuardWidget` traits (mirroring Shield's `HasPageShield`), or stop emitting the subjects and document that page keys are nav/UX signals, not access control. (Resource CRUD — the primary surface — *is* enforced; this is confined to pages/widgets.)

**Context package is read-only (F14).** No write API (tests hand-write INSERTs); the `azguard.context` middleware alias is never auto-registered (silent trap). Auto-alias in `boot()`; ship `guard:context:grant/revoke` + a thin builder reusing `PermissionName`.

**Perf (F29):** `DoctorPage` runs `diagnose()` 3×/render (memoize `runDiagnose()`); `DirectGrantResource` N+1s the user column (batch-resolve via `whereIn`).

**Plugin default (F39):** `AzGuardPlugin` default `panelId='app'` ≠ config `'admin'` — forgetting `->forPanel()` binds an empty panel. Default from config in `getPanelId()`. **Reject** the `new self`→`app(static::class)` sub-claim: the class is `final`.

**Widen compatibility:** the Plugin contract, `Gate::before`, `checkPolicyExistence`, `canAccess`/`canView` are identical across Filament v4/v5 — widen to `^4.0|^5.0` at near-zero cost (make generated-stub namespaces version-aware: `Filament\Schemas`/`Filament\Actions` in v5).

### 4.11 Local `.claude` Toolkit & Governance (F54)

Not runtime surface, but it governs the code. `rector.php:23` skips a moved `BaseRole.php` path (dead skip hiding that `BaseRole` is now Rector-covered). `.claude/agents/azguard-reviewer.md` greps `Http/Controllers/<Domain>` and mandates `*StoreRepository` — architecture this package does **not** have, so its CRITICAL gate can never fire. The shipped Boost skill omits every 0.2 headline contract (`AzGuardUser`, `isSuperAdmin`, `PermissionKey::WILDCARD`, `FakeAzGuardUser`). Two contradictory `CONTRIBUTING.md` files (Russian `develop`-model vs English `main`-model).
- **Action:** Fix the rector skip path; retarget the reviewer to AzGuard's actual (controller-less) architecture; update the Boost skill to the 0.2 API; delete the orphaned Russian `CONTRIBUTING.md` (keep the `.github/` English one that matches CI). Add a CI check that the Boost skill's command names match registered signatures.

---

## 5. Proposed Deprecation / Versioning Path

AzGuard is pre-1.0 with lockstep versioning across satellites. Use the runway before 1.0 to land breaking items with deprecation notices, so 1.0 is a *clean* API.

**0.3.0 (additive + bugfix — no breaks):**
- All Quick Wins and non-breaking 0.3.0 targets (F1–F3, F5–F19, F23–F39, F42–F50, F52–F54).
- Fix all shipped bugs (F1, F2). Ship the full extension-API surface (F5, F7, F16) additively — old paths keep working.
- Reparent exceptions (F9) — non-breaking (`AzGuardException extends RuntimeException`, so `catch(RuntimeException)` is unaffected).
- Introduce `PermissionMatcher` (F21) with the current behavior as default; add `strict_panels`/validator flags **defaulting to lenient** (F46, F47).
- Emit deprecation notices for: facade `'app'` literal defaults (F6 — soft-deprecate positional `'app'`, prefer `null`), `RevokeCommand` (F31/F51 — `@deprecated`, will be removed).
- `AbilitiesDto::toArray()` narrowing (F4): ship `make()` + a *new* `resolvedFlags()`/hardened `toArray()` behind a config/DTO flag; log a deprecation when a non-bool public prop is detected.

**0.4.0 (staged breaks with prior notice):**
- Remove `RevokeCommand`, `PanelManager`, `PendingGrant` (all dead — practically safe, announce in CHANGELOG).
- Flip `AbilitiesDto::toArray()` to bool-only output (F4).
- Wildcard grammar change (F22): default `*`→`[^.]*` + explicit `**`; announce and provide a `legacy_wildcard` opt-out for one cycle.

**1.0.0 (contract freeze):**
- Add `flush()` to `PermissionCatalog` interface (F40) — the only interface *addition* that breaks external re-implementers; do it once, at the major.
- Optionally unify the CLI prefix (F51) — rename `azguard:*` → `guard:*`, keeping the old names as **real** aliases through 1.x.
- Freeze the `@api` surface (F10); from here every `@api` change follows SemVer, PHPStan-enforced.

**Discipline:** every release carries a `CHANGELOG` "Deprecations" section; `@deprecated` PHPDoc on every soft-removed symbol; a `guard:doctor` warning when a deprecated config key/command is in use.

---

## 6. What NOT to Do

These would betray the low-bloat / panel-centric / code-first philosophy. Recorded so contributors don't re-propose them.

1. **Don't extract a shared `ClassScanner` seam** for the three discovery call sites (F31). Each has a different predicate; three sites is below the Rule of Three and adds surface against the not-bloated tenet. Consolidate only if a fourth appears.
2. **Don't add `@template-covariant` / generic key types to `PermissionSet`.** Permission keys are a flat, non-parametric string space; generics add annotation ceremony and variance-confusion risk with no consumer benefit.
3. **Don't gate wildcard matching behind a new swappable class *speculatively*.** Introduce `PermissionMatcher` (F21) only because hierarchical matching is a plausible real second impl — do not multiply strategy contracts for hypothetical futures.
4. **Don't make write-time key validation throw by default (F46).** Hard-throwing on unknown/`*` keys breaks the lenient-catalog / headless philosophy and existing seeds. Opt-in, swappable validator, default lenient.
5. **Don't add a repository/source seam to the context layer (F26) just to rename a table.** A config key on the package's own namespace suffices; a `ContextRoleSource` contract is premature until a second reader exists.
6. **Don't adopt forbid/deny-precedence as the authorization model center.** Union-only (priority = ordering) is a deliberate simplicity win. If negative grants are ever added, model them as another high-priority `GrantSource` with documented precedence, framed as an *exception* mechanism (Bouncer-style) — never the default evaluation shape.
7. **Don't pull ReBAC/OpenFGA or a policy DSL into core.** Position `ListObjects`/conditions/relationship tuples as *optional grant-sources / interop recipes* against the existing `GrantSource`/`PermissionLayer` contracts. Core stays code-first, in-process, panel-centric.
8. **Don't ship the full permission catalog (or wildcard grammar) to the frontend (F37).** Curate a per-actor/per-panel boolean subset; dumping the catalog leaks hidden modules and future features. `abilitiesFor()` must default to curated keys, never `all()`.
9. **Don't make `AzGuardPlugin::make()` container-resolvable for a subclassing reason (F39).** The class is `final`; `new self` under `: static` is a non-issue. Only fix the `'app'`/`'admin'` default mismatch.
10. **Don't rely on nav-hiding as access control** and don't document it as such. `shouldRegisterNavigation()` hides links; URLs remain reachable — enforcement must be a separate, real check (F13).
11. **Don't put every internal class behind an interface.** Each public contract is a permanent BC obligation (Cashier's deliberate opinionatedness validates the low-bloat tenet). Add contracts only at genuine swap seams; keep resolvers/caches `@internal` and final.
12. **Don't add a recording flag into the hot `check()` path for explainability (F16/F53).** Keep `explain()` a separate opt-in re-run; the fast path stays a pure `bool`.
