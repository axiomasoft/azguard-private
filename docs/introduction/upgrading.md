# Upgrading

## 0.3.0 — Wildcard grammar flip (breaking)

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

## Pre-1.0 API cleanup (breaking)

The pre-1.0 cleanup unifies the public API around bare, single-verb names. There
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

No config keys or migrations changed. Existing `config/az-guard.php` and
published migrations remain valid.

## From Spatie Permission

If you are migrating from Spatie's `laravel-permission`, see the [Comparison page](/introduction/comparison) for a feature mapping and the recipes section for migration patterns.
