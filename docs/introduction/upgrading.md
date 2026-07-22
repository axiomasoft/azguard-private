# Upgrading

## 0.2 → 0.3

This release lands the full remediation + fluent/DX redesign wave (see the package's
`ARCHITECT_REVIEW.md` and `2026.07.18-AZGUARD-STABLE` plan): a fail-closed query-scope contract,
a single immutable grant grammar shared by `core` and `context`, a fluent Filament plugin +
static middleware constructors, a domain-canon namespace reshuffle, and the wildcard grammar
flip. There are no compatibility aliases for any of it — update call sites directly. Every
section below gives you a `grep` command to find what needs to change.

### Query-scope: fail-closed by default (breaking)

Scoped-role query isolation used to bypass entirely inside `runningInConsole()` — including
`queue:work`/`schedule:run` jobs running under an authenticated actor, which could silently read
scoped models with **no** filter applied. That bypass is gone: scope isolation now activates
whenever there is an authenticated user (`Auth::check()`), regardless of SAPI/runtime.

If no panel is currently active when a scoped query runs (the exact case the old console bypass
used to mask), a new config key controls the outcome — **default is now the strictest option**:

```php
// config/az-guard.php
'scope' => [
    // 'exception' (default): throws PanelNotSetException — a scoped query with no panel is a bug, fail loudly
    // 'empty': `whereRaw('1=0')` — return nothing rather than guess
    // 'all': the pre-0.3 behavior — apply every scope additively (legacy, explicit opt-in only)
    'on_missing_panel' => 'exception',
],
```

If your application runs scoped queries outside an HTTP request with no panel set (custom
commands, jobs that don't go through a panel-aware entry point), you will start seeing
`PanelNotSetException` unless you either set a panel explicitly or opt back into `'all'`.

```bash
grep -rn "runningInConsole" packages/core/src/Concerns/HasScopedRoles.php  # should be empty after upgrade
```

### Direct-grant fluent grammar unification (breaking)

`core` and `context` used to teach two different grammars for the same operation. There is now
a single immutable fluent root, and the `context` branch is an extension of it, not a separate
constructor:

```php
// Before
AzGuard::forUser($user)->on('app')->grant('posts.edit');
new ContextGrantBuilder($user, 'workspace', $workspaceId)->give('posts.edit'); // separate entry point

// After — one root, context is a branch of it
AzGuard::forUser($user)->on('app')->until($expiresAt)->grant('posts.edit');
AzGuard::forUser($user)->inContext('workspace', $workspaceId)->until($expiresAt)->grant('posts.edit');
```

- `GrantBuilder::expiresAt()` is renamed to `until()` (both `core` and the now-TTL-aware
  `ContextGrantBuilder`).
- Both builders are `final readonly` — every scope-setter returns a new instance; direct
  `new ContextGrantBuilder(...)` is `@internal` (use `AzGuard::forUser()->inContext()` instead).
- Context grants now support expiry (`expires_at` column, new migration — see "Config &
  migrations" below); an expired context grant no longer confers access.
- The positional shorthands `AzGuardManager::grant()`/`revoke()`/`grants()` (and their facade
  `@method` forms) still work but are `@internal` — the documented, public path is the fluent
  root above.

```bash
grep -rn "expiresAt(" packages tests --include='*.php'   # rename to until()
grep -rn "new ContextGrantBuilder(" packages tests --include='*.php'  # switch to AzGuard::forUser()->inContext()
```

### Facade cut-line (breaking)

Two dead-from-the-outside resolver methods are removed from the `AzGuard` facade's `@method`
docblock: `tryPermission()` and `panelIdForPermission()`. They still exist on
`AzGuardManager`/`AzGuardManagerInterface` (real internal seams — `Permissions\PermissionName`
and `Concerns\HasScopedRoles` still call them) but are `@internal` now: calling them via the
facade continues to work at runtime, it is simply no longer a documented, frozen contract.
`AzGuard::isSuperAdmin()` moves to the same `@internal` section — use `$user->isSuperAdmin()`
(`HasPermissions`) instead.

```bash
grep -rn "AzGuard::\(tryPermission\|panelIdForPermission\|isSuperAdmin\)(" . --include='*.php'
```

### Filament: fluent config + static middleware constructors (breaking)

`AzGuardPlugin` gets fluent setters instead of being config-only:

```php
// Before
// config/az-guard-filament.php only

// After — fluent, config remains the fallback
AzGuardPlugin::make()
    ->enforce()
    ->source(RoleResource::class)
    ->abilities(['view', 'edit', 'delete'])
    ->keyTemplate('{panel}.{resource}.{action}')
    ->case(fn ($case) => Str::snake($case));
```

All four parameterized middleware get a static `::using()` constructor alongside the existing
alias-DSL (both remain documented; use whichever reads better at the call site):

```php
// Before (alias-DSL only)
Route::middleware('azguard.grant:posts.edit,app');

// After — static constructor, same effect
Route::middleware(CheckDirectGrant::using('posts.edit', 'app'));
```

**Argument order flip:** `PanelCheckAccess`'s alias changes from `panel,permission` to
`permission,panel` — the same `what,where` order every other alias already used
(`CheckDirectGrant`, `CheckAccess`):

```php
// Before
Route::middleware('azguard.panel_check:app,posts.edit');
// After
Route::middleware('azguard.panel_check:posts.edit,app');
```

```bash
grep -rn "azguard\.panel_check:" . --include='*.php' --include='*.md'  # check argument order at every call site
```

### Structural namespace moves (breaking)

The `AzGuard\Support` catch-all namespace (9 files, no cohesive theme) is dissolved into domain
namespaces; the two root-level types (`PanelProvider`, `PermissionKey`) move alongside their
domains too:

| Old | New |
|---|---|
| `AzGuard\Support\Panel` | `AzGuard\Panels\Panel` |
| `AzGuard\Support\PanelResolver` | `AzGuard\Panels\PanelResolver` |
| `AzGuard\PanelProvider` | `AzGuard\Panels\PanelProvider` |
| `AzGuard\Support\PermissionName` | `AzGuard\Permissions\PermissionName` |
| `AzGuard\PermissionKey` | `AzGuard\Permissions\PermissionKey` |
| `AzGuard\Support\Config` | `AzGuard\Configuration\Config` |
| `AzGuard\Support\RequestState` | `AzGuard\Runtime\RequestState` |
| `AzGuard\Support\ScopedRoleCache` | `AzGuard\Runtime\ScopedRoleCache` |
| `AzGuard\Support\ResolvesGateAbilities` | `AzGuard\Abilities\ResolvesGateAbilities` |
| `AzGuard\Support\BladeHelper` | `AzGuard\Auth\BladeHelper` |
| `AzGuard\Support\Schema\MorphColumns` | `AzGuard\Database\Schema\MorphColumns` |

```bash
grep -rE 'AzGuard\\(Support|PanelProvider|PermissionKey)\b' . --include='*.php'
```

### Contract signature changes (breaking)

Two `@api` contracts changed as part of closing structural PHPStan baseline entries — both only
matter if you have your own class implementing them:

- `Authorizer::check()` now requires `Authorizable&Authenticatable` (was `Authorizable` alone) —
  a plain-`Authorizable`, non-`Authenticatable` user now raises a `TypeError` there instead of
  silently working.
- `Registry\Contracts\PermissionDefinition::label(): ?string` is now part of the contract (it was
  called by Filament resources on the concrete classes without ever being declared). If you
  supply your own `PermissionDefinition` implementation, add `label()`.

```bash
grep -rln "implements PermissionDefinition" packages tests --include='*.php'  # confirm every implementer has label()
```

### Wildcard permission grammar (breaking)

The default wildcard grammar is now the **hierarchical** one
(`HierarchicalPermissionMatcher`): `*` matches exactly **one** dotted segment,
`**` matches recursively. Wildcard patterns in role/grant keys are honoured by
default — the old `features.wildcard_permission` gate no longer switches
patterns off.

What changes for you:

- **Patterns you relied on with the old flag enabled** (`'app.*'` covering
  `app.documents.view`) no longer cross dots. Rewrite them with the grammar in
  mind: `app.*` = one segment, `app.**` = the whole subtree.
- **Patterns that previously did nothing** (flag off — the old default) now
  grant access with segment semantics. Audit your role/grant keys for `*`
  before upgrading if you kept the flag off.
- **Legacy opt-out (one deprecation cycle):** set
  `features.wildcard_permission = true` to restore the 0.2 dot-crossing
  grammar. The flag's old meaning ("honour wildcards at all") is gone; it now
  only selects the legacy grammar and will be removed together with
  `WildcardPermissionMatcher` in the next cycle.
- **`PermissionSet` used standalone** (outside a booted container) now defaults
  to the hierarchical grammar too, matching the application default.
- **Hardening:** a bare `*` surfacing from a custom `MergeStrategy` /
  `PermissionLayer` is now always dropped by the catalog filter — it never
  becomes a superadmin grant, in either grammar. Real superadmin wildcards
  granted by a `GrantSource` are unaffected.

### Config & migrations changed in 0.2 → 0.3

Two config keys got new meaning this cycle (both covered above — listed here for a single
place to check your published config against):

- `az-guard.scope.on_missing_panel` — new key, see "Query-scope" above.
- `az-guard.features.wildcard_permission` — existing key, **inverted meaning**, see "Wildcard
  permission grammar" above.

Two new migrations ship this cycle (publish/run them — no already-applied migration is edited
in place):

- `2026_01_01_000005_add_unique_constraints_to_model_has_roles_and_scopes.php` (core) — adds
  PK/unique constraints to `model_has_roles`/`model_has_scopes`. If you have manually-inserted
  duplicate rows, the migration fails — dedupe before upgrading.
- `2026_01_01_000011_add_expires_at_to_az_guard_context_roles_table.php` (context) — nullable
  `expires_at` column backing the new TTL-parity on context grants (see "Direct-grant fluent
  grammar unification" above).

For a fresh MySQL/MariaDB schema, RBAC composite-key strings use `utf8mb4_bin`: keys that differ
only by letter case are distinct, matching PHP comparison semantics. Review any case-only RBAC
data or assumptions before upgrading; already-migrated databases are not changed in place.

```bash
php artisan migrate --path=vendor/axioma-studio/azguard-core/database/migrations
php artisan migrate --path=vendor/axioma-studio/azguard-context/database/migrations  # if you use the context package
```

## 0.1 → 0.2 — earlier API cleanup (breaking, historical)

> This section predates the 0.2 → 0.3 wave above — it documents the cleanup that shipped getting
> the package to 0.2.0 (bare single-verb public API, `axioma-studio/*` package names, Filament 5).
> Kept for anyone upgrading from a pre-0.2 checkout.

This cleanup unified the public API around bare, single-verb names. There
are no compatibility aliases — update call sites directly. A project-wide
search-and-replace covers almost everything.

### User trait (`HasAzGuard`)

The `Az` prefix is gone; the trait now simply exposes the bare methods from
`HasPermissions` and `HasRoles`.

| Old | New |
|---|---|
| `hasAzPermission()` | `hasPermission()` |
| `hasAzPermissionIn()` | `hasPermissionIn()` |
| `hasAzRole()` | `hasRole()` |
| `getAzPermissions()` | `permissions()` |
| `clearAzPermissionsCache()` | `flushPermissions()` |

### Direct grants — one verb set everywhere

| Old | New |
|---|---|
| `GrantBuilder::give()` | `grant()` |
| `GrantBuilder::list()` | `grants()` |
| `AzGuardManager::grantDirect()` | `grant()` |
| `AzGuardManager::revokeDirect()` | `revoke()` |
| `AzGuardManager::activeGrants()` | `grants()` |
| `HasDirectGrants::grantDirect()` | `grant()` |
| `HasDirectGrants::revokeGrant()` | `revoke()` |
| `HasDirectGrants::hasDirectGrant()` | `hasGrant()` |
| `HasDirectGrants::activeDirectGrants()` | `grants()` |

### Panel builder

| Old | New |
|---|---|
| `Panel::id()` (getter) | `getId()` (`id()` is now a setter only) |
| `Panel::setNamespace()` | `namespace()` |
| `Panel::setBasePath()` | `basePath()` |
| `Panel::getPermissionName()` | use `resolvePermission()` |

### Renamed / removed classes

| Old | New |
|---|---|
| `HasScopes`, `InteractsWithAzScopes` | `HasScopedRoles` |
| `GuardDoctor`, `DiagnosticsService` | `AzGuardDiagnostics` |
| `PermissionResolverCache` | `PermissionCache` |
| `Support\BaseRole` | `Roles\BaseRole` |
| `PermissionSet::toArray()` | `keys()` |
| `Context\Contracts\ContextMergeStrategy` | `Context\Contracts\MergeStrategy` (now `merge($global, $context)`) |
| `ResolvesContext::panel()` | `panelId()` |
| Filament `AzGuardResource` / `GuardResource` | removed — see the Filament guide |

### Search and replace

```bash
grep -rE 'hasAz(Permission|Role)|getAzPermissions|clearAzPermissionsCache' . --include='*.php'
grep -rE '->give\(|grantDirect|revokeDirect|revokeGrant|hasDirectGrant|activeDirectGrants' . --include='*.php'
grep -rE 'GuardDoctor|InteractsWithAzScopes|PermissionResolverCache' . --include='*.php'
```

### Composer package name

The core package is published as `axioma-studio/azguard-core` (the old
`azguard/azguard` name is retired):

```bash
composer remove azguard/azguard
composer require axioma-studio/azguard-core
```

### Filament

The Filament package now requires Filament 5 and replaces the old
`AzGuardResource` / `GuardResource` base classes with a config-driven,
zero-boilerplate model. See the [Filament guide](/basic-usage/filament).

### Config & migrations

No config keys or migrations changed in this earlier cleanup (see "Config & migrations changed
in 0.2 → 0.3" above for what changed since).

## From Spatie Permission

If you are migrating from Spatie's `laravel-permission`, see the [Comparison page](/introduction/comparison) for a feature mapping and the recipes section for migration patterns.
