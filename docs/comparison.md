# Comparison with other packages

Checked on **2026-10-09** against the latest releases on Packagist and their documentation. All of these
packages are mature and maintained, and each fits some applications better than AzGuard. Report an
inaccuracy in an issue and it will be corrected.

| Package | Version checked | Requires (PHP constraint, Laravel, other) |
|---|---|---|
| [spatie/laravel-permission](https://spatie.be/docs/laravel-permission/v8/introduction) | 8.3.0 (2026-07-03) | ^8.3, Laravel 12–13 |
| [silber/bouncer](https://github.com/JosephSilber/bouncer) | 1.0.4 (2026-03-18) | ^8.2, Laravel 11–13 |
| [santigarcor/laratrust](https://laratrust.santigarcor.me/) | 8.5.5 (2026-03-06) | Laravel 10–13 |
| [casbin/laravel-authz](https://github.com/php-casbin/laravel-authz) | 5.1.1 (2026-09-27) | Laravel 10–13, on casbin/casbin 4.6.0 |
| [bezhansalleh/filament-shield](https://github.com/bezhansalleh/filament-shield) | 4.3.1 (2026-07-25) | Filament 4–5, built on spatie/laravel-permission |
| AzGuard | 1.0 | 8.3–8.5, Laravel 11–13, Filament 5 |

## Feature matrix

✅ built in · ➖ possible with your own code · ❌ not supported

| | AzGuard | Spatie | Bouncer | Laratrust | Casbin authz |
|---|---|---|---|---|---|
| Where roles live | PHP classes | Database | Database | Database | Policy file or database |
| Where permissions live | Enums, plus optional runtime permissions | Database (enum values accepted) | Database | Database | Policy file or database |
| Create and edit roles at runtime (admin UI) | ❌ roles are code; permissions can be runtime | ✅ | ✅ | ✅ | ✅ |
| Direct permissions for a user | ✅ | ✅ | ✅ | ✅ | ✅ |
| Wildcard permissions | ✅ granted patterns `posts.*`, `posts.**` | ✅ granted patterns (opt-in) | ✅ `everything()`, `toManage()` | ➖ check patterns only (`isAbleTo('edit-*')`) | ✅ (matcher functions) |
| Explicit deny | ✅ policy veto, restrictions | ❌ | ✅ `forbid()` | ❌ | ✅ deny effect |
| Teams / tenants | ✅ tenants and nested assignment scopes | ✅ teams | ✅ `Bouncer::scope()` | ✅ teams | ✅ domains |
| Permission on one specific model | ➖ via a policy or a scope | ❌ | ✅ `to('edit', $post)`, `toOwn()` | ❌ | ✅ (ABAC model) |
| Expiring grants | ✅ `until:` + pruning | ❌ | ❌ | ❌ | ❌ |
| Several isolated permission sets per app | ✅ panels | ✅ guards | ❌ | ❌ | ✅ (multiple enforcers) |
| Laravel Gate / `@can` | ✅ | ✅ | ✅ | ✅ | ✅ |
| Policy decides an ability, with grants as a precondition | ✅ `#[RequiresGrant]` + policy | ➖ | ➖ | ➖ | ➖ |
| Filtering records by permission | ✅ compiled to SQL ([limits](/guides/checking-access#filtering-lists)) | ❌ | ❌ (`whereIs`/`whereCan` filter users, not records) | ❌ | ❌ |
| Events on changes | ✅ after commit, with actor and reason | ✅ (opt-in, v7+) | — not documented | ✅ | — not documented |
| Cache | Per request; optional Laravel store per subject | One global cache key | Per request; optional cross-request | ✅ | ✅ |
| Diagnostics | `azguard:doctor`, `azguard:explain` | `permission:show` | ❌ | ❌ | ❌ |
| Testing helpers | ✅ fake, `actingAsWithRoles`, contract tests | ➖ | ➖ | ➖ | ➖ |
| Filament integration | First-party `azguard-filament` | Through Filament Shield | Community | Community | ❌ |

## When to choose something else

- **Non-developers create roles in an admin UI.** Spatie, Bouncer and Laratrust store roles in the
  database. AzGuard roles are reviewed in code, and only permissions can be created at runtime.
- **A small app with a few roles.** Spatie has far less to learn: one migration, strings or enums,
  `assignRole()`. Its ecosystem and community answers are much larger.
- **Per-record abilities without writing policies** (`allow($user)->to('edit', $post)`). Bouncer models this
  directly.
- **A formal access model** (RBAC with domains, ABAC, policies shared with services written in other
  languages). Casbin uses one model file across many runtimes.
- **A Filament admin on Spatie today.** Filament Shield is the established choice, with a large community.

## Where AzGuard is different

- **Code-first and typed.** Roles and permissions are classes and enums, so renames are IDE refactors and
  `azguard:doctor` catches mismatches in CI.
- **Fails closed.** An exception in a source, policy, hook or condition denies and logs a code. A check inside
  an unrecognized database transaction denies. An unknown permission throws on the direct API.
- **One decision pipeline for every entry point.** `hasPermission()`, `@can`, middleware, controller
  attributes, Filament and list queries reach the same decision, and `azguard:explain` shows every step.
- **Scopes and expiry are first-class.** A role can be granted on a team for a week, and the grant is pruned
  with an event when it expires.

## Costs to know about

- More concepts than Spatie: panels, authority modes, assignment scopes.
- Each check reads the subject model once, so its attributes are always current for policies and automatic
  roles. Many checks in one request cost one small query each; see [Performance](/advanced/performance).
- Version 1.0 is new. The others have years of production use.

## Corrections to the previous comparison

The previous version of this page made several claims that are not accurate against current releases:

| Previous claim | Correction |
|---|---|
| Spatie: no enums, strings only | Spatie accepts `BackedEnum` values for roles and permissions (`docs/basic-usage/enums`) |
| Spatie: no multi-panel, "manual" | Spatie separates permission sets by guard (`multiple-guards`) |
| Spatie: "Octane issues", "4 MB cache per user" | Spatie documents Octane support (`register_octane_reset_listener`). The size claim had no source and was removed |
| Bouncer: no wildcard permissions | Bouncer has `everything()` and `toManage()` |
| Laratrust: no team scoping ("partial"), no wildcards | Laratrust has teams and accepts patterns when checking (`Str::is`) |
| AzGuard: "custom runtime roles" | 1.0 roles are code only; runtime **permissions** exist |
| AzGuard: `guard:doctor`, `#[RoleOnly]`, `#[SkipGuardCheck]` | 1.0 names: `azguard:doctor`, `#[CheckPermission]`, `#[SkipPermissionCheck]` |
