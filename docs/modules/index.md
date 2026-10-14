# Modules

The `axiomasoft/azguard` package is one Composer package that contains a core and three optional modules. The core
decides access; each module adds one capability and is switched on per panel, never globally.

| Module | Namespace | What it adds | Switched on by |
|---|---|---|---|
| Core | `AzGuard\` | Decisions, panels, roles and permissions, tenants, storage, changes, doctor and explain | always |
| [Scopes](/modules/scopes) | `AzGuard\Scopes\` | Assignment scopes: teams, projects or stores inside a tenant | `ScopesPlugin` on the panel |
| [Audit](/modules/audit) | `AzGuard\Audit\` | A change journal in the database, with retention | `AuditPlugin` on the panel |
| [DevTools](/modules/devtools) | `AzGuard\DevTools\` | `azguard:install`, `azguard:stubs`, `azguard:make:*` | the console (no panel setting) |

Filament is a separate package, [`axiomasoft/azguard-filament`](/guides/filament), built on the same public API as
your own code.

## Activation matrix

A module costs nothing on a panel that does not use it: no service is resolved, no query touches a module table.

| Setup | What happens |
|---|---|
| Core only | A check, grant, revoke or list resolves no module service. There is no `audit_log` table and no scope declaration (mode `None`). |
| `ScopesPlugin` on panel A, not on B | A interprets scope modes and aliases. B answers only global requests; it refuses a scope with `context_not_accepted`, and scoped rows stored for B stay inert. |
| `AuditPlugin` on panel A, not on B, one storage | A writes a journal row for each effective change, with the `event_id` of the dispatched event. B writes none. |
| Two panels with different options | Each panel has its own scope mode and retention; their fingerprints differ. |
| A module removed after use | Stored rows stay. Scoped rows fail closed, journaling stops, and revoking, expiry and pruning still work. |
| DevTools outside the console | No `azguard:make:*`, `azguard:install` or `azguard:stubs` command exists in HTTP or queue workers. |

Because the module code ships inside the package, the Composer autoloader and the service providers of the three
modules are always present. Only a panel that attaches the plugin pays for it.

## How they fit together

```text
            Kernel values
                  ^
   Core contracts and shared values
   (Contracts/*, Context, Tenancy, Schema, Panels)
        ^            ^                 ^
  Decision and    Storage and     Laravel composition
  write engines   adapters        (AzGuardServiceProvider)
        ^
  Scopes   Audit   DevTools   Filament      each depends on the core only
```

- The core never names a module. A module reaches the core only through its supported contracts and values.
- Modules do not depend on each other, and neither does Filament. Two modules cooperate through core values, such as the
  `AccessScope` of a request or the `ChangeRecord` of a change.
- A module can use only the public API and SPI of the core; an `@internal` symbol is off limits to it, as to you.
  What the public API and SPI promise is on [Compatibility](/reference/compatibility).
  That keeps each module movable into its own package later without changing the API, the schema or stored data.

## Configuration

There is one `config/azguard.php`. The core owns every key except `scaffold.*`, which DevTools owns. Audit retention
and scope settings are options of the plugin, not config keys. A package of your own can own a root key too.

## Storage

The core creates six tables: `permissions`, `role_grants`, `permission_grants`, `panel_state`, `subject_revisions`
and `storage_state`. The Audit module owns a seventh, `audit_log`, and creates it with its own migration. Scopes and
DevTools own no table.
