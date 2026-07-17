# Artisan Commands

AzGuard ships runtime commands under the `guard:` prefix (including the
namespaced `guard:context:*` and `guard:catalog:*` / `guard:filament:*`
sub-groups) and scaffolding generators under the `make:guard-` prefix. No
other prefix is used anywhere in the package — this is enforced by an
architecture test (`tests/Feature/CommandPrefixRegistrationTest.php`).

This page is generated from the package's actually-registered command list.
A CI test (`tests/Feature/CliReferenceDriftTest.php`) fails the build if a
command is added, removed, or renamed here without updating this table —
so it should never drift from what `php artisan list` reports.

## Reference

### Core (`axioma-studio/azguard-core`)

| Command | Description |
|---|---|
| `guard:install` | Install AzGuard: publish config and run migrations |
| `guard:doctor` | Check consistency of AzGuard enums, policies, and roles |
| `guard:catalog` | List all permissions from PermissionCatalog |
| `guard:catalog:validate` | Validate consistency between PermissionCatalog, policies, and enums |
| `guard:role-permissions` | Manage DB-level role permissions (`role_permissions`) |
| `guard:role` | Assign or detach a role for a user (`guard:role assign\|detach {user} {role}`) |
| `guard:list-permissions` | List all registered AzGuard permissions grouped by panel |
| `guard:list-scoped-roles` | List all entity-scoped role assignments for a user |
| `guard:grants` | List direct permission grants |
| `guard:sync-roles` | Sync PHP role classes with the `roles` table in the database |
| `guard:cache-reset` | Flush the AzGuard permission cache store |
| `guard:grant` | Issue a direct grant to an AzGuard user |
| `guard:revoke-grant` | Revoke direct grant(s) for an AzGuard user |
| `guard:prune-grants` | Delete all expired direct grants |
| `guard:super-admin` | Grant a user the super-admin role (wildcard access) |
| `guard:explain` | Explain WHY a user was granted or denied an ability |
| `guard:abilities` | List the fully-resolved abilities for a user in a panel |
| `make:guard-panel` | Scaffold a guard panel with Permissions/Policies/Abilities domain structure |
| `make:guard-permission` | Add a case to an existing Permissions enum or create one |
| `make:guard-policy` | Create a policy stub with `GuardPolicy` and `GateAbility` attributes |
| `make:guard-abilities` | Create an Abilities DTO based on `AbilitiesDto` |
| `make:guard-role` | Create a new role for a specific panel |

### Context (`axioma-studio/azguard-context`)

| Command | Description |
|---|---|
| `guard:context:grant` | Issue a context-scoped grant to an AzGuard user |
| `guard:context:revoke` | Revoke context-scoped grant(s) for an AzGuard user |

### Filament (`axioma-studio/azguard-filament`)

| Command | Description |
|---|---|
| `guard:filament:generate` | Generate the AzGuard permission schema for a Filament panel |

## `guard:install`

Publishes the config file and runs pending migrations in one step — the
fastest way to get started in a fresh application:

```bash
php artisan guard:install
```

## `guard:doctor`

Runs a suite of checks and prints a report:

```bash
php artisan guard:doctor
php artisan guard:doctor --panel=app
php artisan guard:doctor --json
```

Checks performed:

- Config `panels` array is not empty
- All registered panel provider classes exist
- Required migrations have been run
- Every registered permission enum is a valid backed enum
- Every role class implements `RoleInterface`
- No duplicate resolved permission strings across enums
- All `#[GateAbility]` methods have a corresponding policy class
- Cache store is reachable

## `guard:catalog` / `guard:catalog:validate`

`guard:catalog` lists every permission known to `PermissionCatalog`:

```bash
php artisan guard:catalog
php artisan guard:catalog --panel=app
php artisan guard:catalog --group=documents
php artisan guard:catalog --format=json   # table (default) | json | csv
```

`guard:catalog:validate` cross-checks the catalog against policies and enums
(useful in CI to catch a permission that exists in an enum but has no
`#[GateAbility]`-backed policy method, or vice versa):

```bash
php artisan guard:catalog:validate
php artisan guard:catalog:validate --panel=app --strict
```

## `guard:role` and `guard:role-permissions`

`guard:role` assigns or detaches a class-based or `DynamicRole` role for a
user:

```bash
php artisan guard:role assign 42 EditorRole
php artisan guard:role detach 42 EditorRole
```

`guard:role-permissions` manages the DB-level `role_permissions` table for
`DynamicRole` instances (`list`, `add`, `remove`, `sync`):

```bash
php artisan guard:role-permissions list Editor --panel=app
php artisan guard:role-permissions add Editor app.documents.export --panel=app
php artisan guard:role-permissions remove Editor app.documents.export --panel=app
php artisan guard:role-permissions sync Editor --keys=app.documents.view,app.documents.export --panel=app
```

## `guard:list-permissions` / `guard:list-scoped-roles`

```bash
php artisan guard:list-permissions          # all panels
php artisan guard:list-permissions app      # filter by panel

php artisan guard:list-scoped-roles 42
php artisan guard:list-scoped-roles 42 --entity="App\Models\Project"
```

## `guard:sync-roles`

Syncs static role metadata (name, panel, level) to the `roles` table. Useful
after adding a new static role in CI/CD:

```bash
php artisan guard:sync-roles
php artisan guard:sync-roles --panel=app
php artisan guard:sync-roles --dry-run
```

::: warning
This command does **not** assign roles to users. It only ensures the role
record exists so custom UI can reference it.
:::

## `guard:cache-reset`

```bash
php artisan guard:cache-reset          # prompts for confirmation
php artisan guard:cache-reset --force  # skip the confirmation prompt
```

Run this after modifying a role's `permissions()` array in code and
deploying, so the new permissions take effect immediately.

## Direct grants — `guard:grant` / `guard:grants` / `guard:revoke-grant` / `guard:prune-grants`

```bash
# Issue a grant (optionally with a TTL, in seconds)
php artisan guard:grant 42 app.documents.export app --ttl=3600

# List active grants
php artisan guard:grants
php artisan guard:grants --user=42 --panel=app
php artisan guard:grants --format=json   # table (default) | json | csv

# Revoke a specific grant, or all grants for a panel
php artisan guard:revoke-grant 42 app.documents.export app
php artisan guard:revoke-grant 42 ignored app --all --force

# Clean up expired grants (schedule this — see below)
php artisan guard:prune-grants
php artisan guard:prune-grants --panel=app
```

Schedule pruning so expired grants don't accumulate:

```php
$schedule->command('guard:prune-grants')->daily();
```

::: tip
If `az-guard.prune_expired_daily` is enabled in config, AzGuard schedules
this automatically — no manual `$schedule->command()` call needed.
:::

## `guard:super-admin`

Promotes a user to the wildcard-access super-admin role:

```bash
php artisan guard:super-admin --user=1
```

## `guard:explain`

Explains, step by step, why a user was granted or denied a given ability —
which resolver (class role, DB role, direct grant) produced the decision:

```bash
php artisan guard:explain 42 app.documents.export --panel=app
php artisan guard:explain 42 app.documents.export --panel=app --json
```

## `guard:abilities`

Lists the fully-resolved set of abilities for a user in a panel (the same
shape an `AbilitiesDto` would compute):

```bash
php artisan guard:abilities 42 --panel=app
php artisan guard:abilities 42 --panel=app --json
```

## Context commands (`axioma-studio/azguard-context`)

`guard:context:grant` and `guard:context:revoke` manage entity-scoped direct
grants (a grant tied to a specific context, e.g. a workspace):

```bash
php artisan guard:context:grant 42 app.documents.export app workspace 7
php artisan guard:context:revoke 42 app.documents.export app workspace 7
php artisan guard:context:revoke 42 ignored app workspace 7 --all --force
```

## Filament commands (`axioma-studio/azguard-filament`)

`guard:filament:generate` regenerates the permission schema consumed by the
Filament panel integration:

```bash
php artisan guard:filament:generate
php artisan guard:filament:generate --source=enum --panel=app
php artisan guard:filament:generate --dry-run
```

## Scaffolding commands

```bash
# Scaffold a whole panel domain (Permissions/Policies/Abilities) at once
php artisan make:guard-panel App Invoices
# → app/Guards/App/Invoices/...

# Add a case to (or create) a permission enum: panel, domain, case
php artisan make:guard-permission App Invoices View
# → app/Guards/App/Invoices/Permissions/InvoicesPermission.php

# New policy stub with #[GuardPolicy] / #[GateAbility]
php artisan make:guard-policy App Invoices
# → app/Guards/App/Invoices/Policies/InvoicesPolicy.php

# New Abilities DTO
php artisan make:guard-abilities App Invoices
# → app/Guards/App/Invoices/Abilities/InvoicesAbilities.php

# New role class (interactive — prompts for panel and name)
php artisan make:guard-role
```
