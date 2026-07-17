# Changelog

All notable changes to `axioma-studio/azguard-context` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Write-API for context grants (F14).** The package was previously read-only
  (grant rows had to be inserted by hand via `DB::table()`). New
  `ContextGrantBuilder` mirrors the core `GrantBuilder`: a fluent
  `->on()->inContext()->grant()/revoke()/revokeAll()/grants()` that fires
  `ContextGrantGiven` / `ContextGrantRevoked` and busts the `PermissionCache`
  on write. Backed by a new `ContextRole` Eloquent model over
  `az_guard_context_roles`. New console commands `guard:context:grant` /
  `guard:context:revoke` mirror `guard:grant` / `guard:revoke-grant` (same
  `ResolvesUserModel` concern). No migration required — the existing unique
  index already covers the write path.
- **Auto-registered `azguard.context` middleware alias (F14).**
  `AzGuardContextServiceProvider::boot()` now registers the
  `azguard.context` → `SetAuthorizationContext` alias itself. Previously it
  lived only in a docblock example, so host apps had to wire it manually in
  `bootstrap/app.php` (a silent trap); routes can now use the string alias
  directly.

### Fixed
- **Context-roles table name is read from the context package's own config
  (F26).** `ContextPermissionLayer` read
  `config('az-guard.table_names.context_roles')` — a key that does not exist in
  core's `az-guard.php`, so the lookup always fell through to the default. The
  key now lives in `az-guard-context.php` and the reader points at
  `az-guard-context.table_names.context_roles`.
- `ContextGuard::checkInContext` no longer busts the durable cross-request
  permission cache on every check. It now uses the resolver's in-process-only
  `forgetRequestCache()` for its transient set/restore of the active context,
  instead of `forgetForUser()`, which (as of core F30) bumps the persistent
  per-user epoch — previously turning each context check into a full
  cross-request cache invalidation and unbounded epoch growth on a persistent
  store. (F30-fix)

## [0.1.0]

Initial release — opt-in multi-workspace / multi-site authorization context:
`AuthorizationContext` value object, the context manager and guard, and the
context-aware permission merge strategies.
