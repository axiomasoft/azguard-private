# AzGuard public surface — post-cut-line registry (P3.1)

> **Status:** produced by P3.1 (sonnet/high) after executing `root/contracts/facade-cutline.md`
> (P2.5, D29). This is a **reality registry** — written from the code on the item-commit tree,
> not from the spec — and is the input for the snapshot gate (P3.2) and the SemVer catalog
> (P3.3). Scope: **core package only** (`packages/core/src`), same boundary as the existing
> `tests/Unit/ApiBoundaryTest.php` and the P3.2 snapshot (context/filament are documented but
> untagged — see §7).

## 1. Facade `AzGuard\Facades\AzGuard` — final @method surface (post cut-line)

Cut-line removed 2 `@method` lines (`tryPermission`, `panelIdForPermission`) from the facade
docblock and consolidated an explicit `@internal` section (`grant`/`revoke`/`grants` — already
done by P2.3 — plus `isSuperAdmin`, newly moved here). The two removed methods still exist on
`AzGuardManager`/`AzGuardManagerInterface`, tagged `@internal` (facade-cutline.md §3/D29 —
real internal seams, not dead code).

**@api (11 orchestration methods):**

| Method | Purpose |
|:--|:--|
| `registerPanel(Panel\|callable $panel)` | Register a panel |
| `getPanels(): array<string, Panel>` | Introspect all registered panels |
| `panel(string\|BackedEnum $id): ?Panel` | Look up a panel by id |
| `currentPanel(): ?Panel` | Current request panel |
| `setCurrentPanel(?Panel $panel): void` | Set the current panel |
| `permission(string\|BackedEnum $panelId, string\|UnitEnum $permission): string` | Resolve a fully-qualified permission key |
| `registerGrantSource(class-string<GrantSource> $sourceClass)` | Extension seam: extra grant source |
| `registerCatalogBuilder(class-string<PermissionCatalogBuilder> $builderClass)` | Extension seam: extra catalog contributor |
| `abilitiesFor(Authenticatable $user, ..., array $keys): array<string, bool>` | Curated frontend ability projection |
| `hasContextGuard(): bool` | Container-level check — usable without a user; per-user equivalent is `$user->hasContextGuard()` (`HasPermissions`) |
| `forUser(Authenticatable $user): GrantBuilder` | Fluent grant-grammar root (D16) |

**@api Testing (3 + `fake()`):** `assertGranted()` · `assertDenied()` · `assertChecked()` ·
`fake(): AzGuardFake` (real static method, not a `@method` annotation).

**`@internal` (kept for internal orchestration, not part of the public contract):**
`grant()` · `revoke()` · `grants()` (positional twins of the fluent root — P2.3) ·
`isSuperAdmin()` (positional twin of `$user->isSuperAdmin()` — moved here by P3.1).

**Removed from the docblock, methods retained as `@internal` on
`AzGuardManager`/`AzGuardManagerInterface`:** `tryPermission()` (real seam:
`Permissions\PermissionName::resolve()`) · `panelIdForPermission()` (real seam:
`Concerns\HasScopedRoles` panel derivation from an enum permission).

## 2. `Contracts/` — cross-cutting `@api` contracts (core)

`AbilitiesResolver` · `AzGuardManagerInterface` · `AzGuardUser` · `ContextGrantBuilder` ·
`ContextGrantBuilderFactory` · `ContextGuard` · `HasDirectGrants` · `HasPermissions` ·
`HasRoles` · `HasScopedRoles` · `PermissionContext` · `PermissionLayer` · `PermissionMatcher` ·
`Permission` · `PermissionResolverInterface` · `RoleInterface` · `RolePermissionValidator` ·
`ScopeInterface` (18 contracts — `ApiBoundaryTest` enforces every file under `Contracts/`
carries `@api`).

`ContextGrantBuilder`/`ContextGrantBuilderFactory` are the registered-extension seam the
`context` package binds against (P2.3) — core stays context-agnostic; they live here, not in
`Registry\Contracts`, because they are cross-cutting (any grant consumer), not
permission-registry-specific.

## 3. `Registry/Contracts/` — subdomain `@api` contracts (core)

`GrantPriority` (enum) · `GrantSource` · `PermissionCatalogBuilder` · `PermissionCatalog` ·
`PermissionDefinition` · `PermissionMeta` (6 contracts). Deliberately a **separate namespace**
from `Contracts/` — two-domain canon (P2.1/D15): `Contracts/` = cross-cutting, `Registry/
Contracts/` = permission-registry-local (locality over a single flat contracts folder).

## 4. Testing `@api` surface (core)

`Testing\AzGuardFake` (the `fake()` recording double) · `Testing\FakeAzGuardUser` ·
`Testing\FakeGrantSource` · `Testing\Recorded` (the value object handed to assertion closures).

## 5. Public VO / enum (core, outside Contracts/Testing)

`Panels\Panel` (`@api`) · `Permissions\PermissionKey` (`@api` — the permission-key grammar
constants) · `Registry\Contracts\GrantPriority` (`@api` enum, listed in §3) ·
`Registry\Values\PermissionSet` (`@api`).

## 6. Middleware — static constructors + alias-DSL (both canon, both documented)

| Alias | Class | `::using()` signature |
|:--|:--|:--|
| `azguard.roles` | `LoadAzGuardRoles` | — (no static constructor; alias only) |
| `azguard.panel` | `SetCurrentPanel` | `using(string\|BackedEnum $panelId)` |
| `azguard.check` (config-renameable) | `CheckAccess` | `using()` — attribute-driven, no args |
| `azguard.grant` | `CheckDirectGrant` | `using(string\|BackedEnum $permission, string\|BackedEnum\|null $panelId = null)` |
| `azguard.panel_check` | `PanelCheckAccess` | `using(string\|BackedEnum $permission, string\|BackedEnum $panelId)` — canonical arg order `permission,panel` (P2.4 breaking flip from `panel,permission`) |

Blade directives (`AzGuardServiceProvider::boot()`): `@azcan`/`@endazcan`,
`@elseazcan`/`@unlessazcan`/`@endunlessazcan`, `@azrole`/`@endazrole`,
`@azdirect`/`@endazdirect`.

## 7. Config keys (fallback surface, config→fluent canon P2.4)

`packages/core/config/az-guard.php`: `manager` · `resolver` · `matcher` · `abilities_resolver`
· `role_permission_validator` · `models` · `models_namespace` · `table_names` ·
`column_names` · `panels` · `default_panel` · `strict_panels` · `scope` (incl.
`on_missing_panel` fail-closed enum, D10) · `middleware` · `cache` · `grant_sources` ·
`fail_on_source_exception` · `prune_expired_daily` · `features` (incl. `wildcard_permission`
legacy opt-out, D18) · `teams`.

`packages/filament/config/az-guard-filament.php` (all fallback for `AzGuardPlugin` fluent
setters, P2.4): `panel` · `enforce` · `source` · `abilities` · `pages`/`widgets` · `key`/`case`
· `exclude` · `user_label_column` · `super_admin` · `generation`.

`AzGuardPlugin` fluent surface (mutable by Filament convention, not immutable like the grant
builders): `make()` · `forPanel()` · `enforce()`/`isEnforcing()` · `source()`/`getSource()` ·
`abilities()`/`getAbilities()` · `keyTemplate()`/`getKeyTemplate()` · `case()`/`getCase()` ·
`register()` · `boot()`.

## 8. Explicitly out of this registry's scope

- **`context`/`filament` packages** don't run `ApiBoundaryTest`'s class-level `@api` convention
  — `filament` has zero tagged files; `context` has exactly 2 (`ContextGrantBuilder`,
  `ContextGrantBuilderFactory` — both carry a method-level `@internal` docblock on their
  wiring-only constructors, consistent with core's `@internal` usage, but neither file is
  class-level `@api`-tagged). The P3.2 snapshot gate covers core only, matching
  `ApiBoundaryTest`'s existing boundary; extending the convention to the satellite packages is
  a follow-up (P3.2 Scope Excluded already names this; catalog it in P3.3).
- **`Attributes/*`** (`CheckPermission`, `GuardPolicy`, `GateAbility`, `SkipGuardCheck`,
  `RoleOnly`) are documented, consumer-facing PHP attributes but carry no `@api` tag — this
  predates P2.1 (never lived in `Support/`, not part of the cut-line) and is a pre-existing
  tagging-hygiene gap, not a P3.1 defect; noted here for `root/known-limitations.md` (P3.3),
  not fixed by this item (Code Guidance: no scope-creep beyond the spec).
- Artisan commands (`Commands/*`) are a documented CLI surface but outside the reflection-tag
  scheme entirely (attributes/commands aren't `use`-imported contracts); not part of the
  snapshot's unit of freeze.

## 9. Reproducibility

- `@api`-tagged core types: `grep -rlE '@api\b' packages/core/src --include='*.php' | sort`
  → 32 files (list in §2–§5 above).
- Facade docblock content: `sed -n '23,58p' packages/core/src/Facades/AzGuard.php`.
- Middleware aliases: `grep -n "aliasMiddleware" packages/core/src/AzGuardServiceProvider.php`.
- `context`/`filament` tag coverage: `grep -rlE '@api|@internal' packages/{context,filament}/src
  --include='*.php'` → 2 hits, both in `context` (see §8), 0 in `filament`.
