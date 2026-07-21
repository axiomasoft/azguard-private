---
name: azguard
bucket: azguard
version: 0.1.0
description: "Roles & access control via azguard package: code-first RBAC, panels, scoped roles, direct grants, frontend abilities"
risk: write
persona: oss-dev
tags: ["php", "laravel", "permissions", "azguard", "rbac", "authorization", "gate", "enum"]
requires: []
produces_for: []
outputs: []
snippets:
  - role-class.php
  - permission-enum.php
  - panel-provider.php
  - policy-gateability.php
  - scoped-roles.php
  - direct-grants.php
  - abilities-dto.php
  - inertia-share.php
  - testing.php
adapters: [claude, cursor, fable]
sha256: ""
---

## Context

RBAC for Laravel via package **azguard** (`azguard/azguard`). Code-first: perms/roles live in code, versioned in git, reviewed in PR (not drifting in DB like spatie/permission). Package monorepo: `core` (RBAC), `filament` (Filament integration), `context` (contextual perms).

Principles:

- **Roles = PHP classes** implementing `RoleInterface` with `getName()` / `getLevel()` / `permissions()`. `getLevel()` is for comparisons (`$user->hasRoleLevel('>= 50')`), not permission inheritance. Wildcard `['*']` in `permissions()` = super-admin (fires in `Gate::before`).
- **Permissions = backed enum** with attributes `#[GateAbility]` (registered in Gate) and `#[RoleOnly]` (only `hasPermission()`, never in Gate). One enum per resource; keys `{panel}.{resource}.{action}` — panel prefix added automatically.
- **Trait `HasAzGuard` on User** (+ `HasDirectGrants` for direct grants): `assignRole()`, `hasRole()`, `hasPermission()`, `syncRoles()`; query scopes `User::role()` / `User::permission()`.
- **Panels = isolated permission namespaces** (`app`, `admin`, `api`). Declared by a `PanelProvider` class (`id`, `path`, `permissionEnums`, `roleClasses`), registered in `config/az-guard.php` → `panels`. One user can hold different roles in different panels.
- **Permission resolution order**: class roles → db roles (DynamicRole) → direct grants (`EffectivePermissionResolver` aggregates GrantSources by priority).
- **Gate-first**: in PHP always `Gate::allows(DocumentsPermission::View)` with enum case, never raw strings (typo = silent security hole). Strings only in middleware/Blade via `->value`.
- **Policy auto-registration**: class with `#[GuardPolicy(model: Document::class)]`, methods with `#[GateAbility(permission: Enum::Case)]` — PanelProvider scans `**/Policies/**/*Policy.php` and calls `Gate::define()` / `Gate::policy()` itself.
- **Scoped roles**: role on a concrete entity (`assignScopedRole(EditorRole::class, $project)`); `Gate::allows('app.documents.edit', $project)` uses scoped resolution automatically.
- **Direct grants with TTL**: exception, not the main pattern — `AzGuard::forUser($user)->on('app')->ttl(3600)->give(...)`; expired grants cleaned by `az-guard:prune-grants`.
- **Cache**: default in-memory per-request; cross-request — `config/az-guard.php` → `cache.store = redis`. Reset: `php artisan azguard:cache-reset`; in code — `$user->flushPermissions()`.

Install:

```bash
composer require azguard/azguard
php artisan vendor:publish --tag=az-guard-config
php artisan migrate
# then trait HasAzGuard on User
```

## Algorithm

1. Install package, publish config, run migrations, add `HasAzGuard` to User.
2. Create panel: `panel-provider.php` + register in `config/az-guard.php`.
3. Define permissions as enums (`permission-enum.php`) and roles as classes (`role-class.php`); register them in PanelProvider.
4. Model-aware checks → policies with auto-registration (`policy-gateability.php`).
5. Roles on a concrete entity (multi-tenant, teams) → `scoped-roles.php`.
6. Pointwise/temporary perms for one user → `direct-grants.php` (granting one perm to 5+ users = make a role).
7. Frontend: per-resource flags → `abilities-dto.php`; global permission map → `inertia-share.php`.
8. Test both outcomes (has perm / lacks perm) → `testing.php`.
9. Verify config: `php artisan azguard:doctor`.

## Snippet selection

| Situation | File |
|---|---|
| New role (static class, levels, wildcard) | `snippets/role-class.php` |
| New perms (enum, `#[GateAbility]`/`#[RoleOnly]`, checks) | `snippets/permission-enum.php` |
| New panel / register enums and roles | `snippets/panel-provider.php` |
| Model-aware perm check, policy auto-registration | `snippets/policy-gateability.php` |
| Role on a concrete entity (Project/Team/Document) | `snippets/scoped-roles.php` |
| Temporary/one-off perm for one user | `snippets/direct-grants.php` |
| Pass "what's allowed on this resource" to frontend (Inertia) | `snippets/abilities-dto.php` |
| Global permission map on frontend, TS constants, Vue | `snippets/inertia-share.php` |
| Tests for roles, perms, grants (Pest) | `snippets/testing.php` |

## Quality checklist

- [ ] PHP code uses only enum cases; raw permission strings only in middleware/Blade via `->value`
- [ ] Check permissions, not roles (`hasPermission()` / `Gate::allows()`, not `hasRole()`)
- [ ] One enum per resource; CRUD set `view/create/edit/delete` as baseline
- [ ] `#[GateAbility]` stated explicitly even where it's the default
- [ ] Direct grant instead of role only for one-off exceptions; TTL for temporary access; `az-guard:prune-grants` in scheduler
- [ ] Abilities passed at page level, not in global shared props; frontend checks are UX only — server always validates
- [ ] Every "allowed" has an "denied" test; between state changes in a test — `$user->flushPermissions()`
- [ ] `php artisan azguard:doctor` green after changes

## Links

- Source: `/home/vostrikov/projects/packages/azguard` (docs/guide: basic-usage, permissions, roles, panels, entity-scopes, direct-grants, policies-and-gates, abilities-frontend, testing)
- Manual RBAC without package — skill `laravel-auth/laravel-permissions`
- Filament integration — package `azguard/filament`, skill `laravel-filament/filament`
- Declarative perm checks on actions via attribute — `laravel-auth/attribute-authorization` (orthogonal layer over Gate)
- Enum perms as metadata/type-safe layer — `laravel-architecture/enum-attributes`
- Abilities to frontend (Inertia/TS) — `frontend-backend/backend-type-sync`
- Auth strategy & threat modeling at architecture level — `architect/security-design`

<!-- ru-source-sha256: a5def4bc03213589301918e9f48acbcfc89d3cf6f4a006a42d92eea4832a8882 -->
