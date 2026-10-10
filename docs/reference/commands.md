# Console commands

This page is generated from `php artisan list azguard --format=json` (AzGuard 0.7.0, Laravel 13).
`php artisan help <command>` shows the same text. A subject is written as `type:id`, where the type is the morph alias.
`azguard:filament:generate` comes from `axiomasoft/azguard-filament`.

## Setup

### `azguard:doctor`

Check the AzGuard configuration, panels, storages, sources and plugins.

```
azguard:doctor [--panel [PANEL]] [--storage [STORAGE]] [--production] [--json]
```

| Argument / option | Description |
|---|---|
| `--panel=` | Check only these panels (and the storages they use) |
| `--storage=` | Check only these storages (and the panels that use them) |
| `--production` | Check a production deployment: catalog cache and build id |
| `--json` | Print the findings as JSON |

### `azguard:install`

Install AzGuard: publish the configuration, choose the storage, create the first panel, migrate and check.

```
azguard:install [--migrate] [--force] [--connection [CONNECTION]] [--host-keys [HOST-KEYS]] [--panel [PANEL]]
```

| Argument / option | Description |
|---|---|
| `--migrate` | Run the migrations of AzGuard |
| `--force` | Overwrite the published configuration and the connection in .env |
| `--connection=` | The database connection of the AzGuard tables |
| `--host-keys=` | The type of the keys of the host models: string, bigint, uuid or ulid |
| `--panel=` | Create the first panel with this name |

### `azguard:storage:migration`

Generate a migration for a configured AzGuard storage.

```
azguard:storage:migration <name>
```

| Argument / option | Description |
|---|---|
| `name` | Configured non-default storage |

### `azguard:stubs`

Publish the stubs of the AzGuard generators.

```
azguard:stubs [--force]
```

| Argument / option | Description |
|---|---|
| `--force` | Overwrite the stubs that are already published |

## Generators

### `azguard:make:models`

Create the grant models of a panel and the migration of their columns.

```
azguard:make:models [--storage [STORAGE]] [--force] [--] <panel>
```

| Argument / option | Description |
|---|---|
| `panel` | The panel directory, such as Admin |
| `--storage=` | The storage whose tables get the columns |
| `--force` | Overwrite the files when they exist |

### `azguard:make:panel`

Create the directory and provider of an AzGuard panel.

```
azguard:make:panel [--model [MODEL]] [--force] [--] <panel>
```

| Argument / option | Description |
|---|---|
| `panel` | The panel directory, such as Admin |
| `--model=` | The subject model of the panel; the model of the users provider by default |
| `--force` | Overwrite the provider when it exists |

### `azguard:make:permission`

Create the permission enum of a group of a panel, with its policy and abilities on request.

```
azguard:make:permission [--model [MODEL]] [--authority [AUTHORITY]] [--case [CASE]] [--policy] [--abilities] [--force] [--] <panel> <group>
```

| Argument / option | Description |
|---|---|
| `panel` | The panel directory, such as Admin |
| `group` | The group of the permissions, such as Orders or Sales/Orders |
| `--model=` | The model of the group, for #[Resource] |
| `--authority=` | Who decides the permissions: grants (a grant is required) or policy (the policy alone decides; its file is always created) |
| `--case=` | A case as Name=local.key, repeatable; when given, the cases replace the five CRUD ones |
| `--policy` | Also create the policy of the group |
| `--abilities` | Also create the abilities DTO of the group |
| `--force` | Overwrite the files when they exist |

### `azguard:make:pipe`

Create a change pipe of an AzGuard panel.

```
azguard:make:pipe [--panel [PANEL]] [--shared] [--force] [--] <name>
```

| Argument / option | Description |
|---|---|
| `name` | The pipe, such as RequireReason |
| `--panel=` | Put it in this panel |
| `--shared` | Put it where panels share things |
| `--force` | Overwrite the file when it exists |

### `azguard:make:plugin`

Create a plugin of an AzGuard panel.

```
azguard:make:plugin [--panel [PANEL]] [--shared] [--force] [--] <name>
```

| Argument / option | Description |
|---|---|
| `name` | The plugin, such as AuditTrail |
| `--panel=` | Put it in this panel |
| `--shared` | Put it where panels share things |
| `--force` | Overwrite the file when it exists |

### `azguard:make:policy`

Create the policy of a group of a panel with #[Decides] for each permission.

```
azguard:make:policy [--enum [ENUM]] [--force] [--] <panel> <group>
```

| Argument / option | Description |
|---|---|
| `panel` | The panel directory, such as Admin |
| `group` | The group of the policy, such as Orders or Sales/Orders |
| `--enum=` | The permission enum to decide, as a class name; the policy is bound to it with #[PolicyFor] |
| `--force` | Overwrite the file when it exists |

### `azguard:make:restriction`

Create a restriction of an AzGuard panel.

```
azguard:make:restriction [--panel [PANEL]] [--shared] [--force] [--] <name>
```

| Argument / option | Description |
|---|---|
| `name` | The restriction, such as AccountLocked |
| `--panel=` | Put it in this panel |
| `--shared` | Put it where panels share things |
| `--force` | Overwrite the file when it exists |

### `azguard:make:role`

Create a role of a panel with an explicit key.

```
azguard:make:role [--force] [--] <panel> <name>
```

| Argument / option | Description |
|---|---|
| `panel` | The panel directory, such as Admin |
| `name` | The role, such as Manager |
| `--force` | Overwrite the file when it exists |

### `azguard:make:source`

Create a source of an AzGuard panel.

```
azguard:make:source [--panel [PANEL]] [--shared] [--grants] [--permissions] [--roles] [--policies] [--force] [--] <name>
```

| Argument / option | Description |
|---|---|
| `name` | The source, such as Ldap |
| `--panel=` | Put it in this panel |
| `--shared` | Put it where panels share things |
| `--grants` | Give grants (ProvidesGrants) |
| `--permissions` | Give permissions (ProvidesPermissions) |
| `--roles` | Give roles (ProvidesRoles) |
| `--policies` | Give policy bindings (ProvidesPolicies) |
| `--force` | Overwrite the file when it exists |

## Panels and catalog

### `azguard:catalog:cache`

Cache the static permission catalogs of all AzGuard panels.

```
azguard:catalog:cache
```

### `azguard:catalog:clear`

Remove the AzGuard permission catalog cache.

```
azguard:catalog:clear
```

### `azguard:catalog:list`

List the permissions of an AzGuard panel.

```
azguard:catalog:list [--panel [PANEL]] [--tenant [TENANT]] [--json]
```

| Argument / option | Description |
|---|---|
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--tenant=` | type:id, required for a panel with tenants |
| `--json` | Print JSON |

### `azguard:panels:list`

List the AzGuard panels with their settings, sources and schema.

```
azguard:panels:list [--settings] [--sources] [--schema] [--json]
```

| Argument / option | Description |
|---|---|
| `--settings` | Show the effective settings and where each value came from |
| `--sources` | Show the sources of each panel, what each contributes, and the plugins |
| `--schema` | Show the schema of permissions and roles |
| `--json` | Print JSON |

### `azguard:roles:list`

List the code roles of an AzGuard panel and how many subjects hold them.

```
azguard:roles:list [--panel [PANEL]] [--tenant [TENANT]] [--json]
```

| Argument / option | Description |
|---|---|
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--tenant=` | type:id, required for a panel with tenants |
| `--json` | Print JSON |

### `azguard:sources:list`

List the named AzGuard sources, their parameters and the panels that use them.

```
azguard:sources:list [--json]
```

| Argument / option | Description |
|---|---|
| `--json` | Print JSON |

### `azguard:state:reset`

Start a new AzGuard state of a panel after a manual change or a restore.

```
azguard:state:reset [--force] [--] <panel>
```

| Argument / option | Description |
|---|---|
| `panel` | The panel |
| `--force` | Reset in production |

## Grants

### `azguard:grants:list`

List the stored AzGuard grants of a subject.

```
azguard:grants:list [--panel [PANEL]] [--tenant [TENANT]] [--json] [--] <subject>
```

| Argument / option | Description |
|---|---|
| `subject` | type:id, or the id when there is one subject model |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--tenant=` | type:id, required for a panel with tenants |
| `--json` | Print JSON |

### `azguard:grants:prune`

Remove expired AzGuard grants.

```
azguard:grants:prune [--panel [PANEL]] [--tenant [TENANT]] [--before [BEFORE]] [--dry-run]
```

| Argument / option | Description |
|---|---|
| `--panel=` | Only this panel |
| `--tenant=` | type:id, only this tenant of the panel |
| `--before=` | ISO-8601; remove grants that expired by then (default: now) |
| `--dry-run` | Count the expired grants without removing them |

### `azguard:permissions:grant`

Grant an AzGuard permission to a subject.

```
azguard:permissions:grant [--panel [PANEL]] [--on [ON]] [--until [UNTIL]] [--field [FIELD]] [--tenant [TENANT]] [--origin [ORIGIN]] [--] <subject> <permission>
```

| Argument / option | Description |
|---|---|
| `subject` | type:id, or the id when there is one subject model |
| `permission` | Name, name with the panel prefix, panel:name or a pattern |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--on=` | type:id of the assignment scope; tenant-wide without it |
| `--until=` | ISO-8601 expiry |
| `--field=` | A grant field as key=value; repeat for more |
| `--tenant=` | type:id, required for a panel with tenants |
| `--origin=` | The origin of the grant (manual by default) |

### `azguard:permissions:revoke`

Revoke an AzGuard permission from a subject.

```
azguard:permissions:revoke [--panel [PANEL]] [--on [ON]] [--tenant [TENANT]] [--origin [ORIGIN]] [--] <subject> <permission>
```

| Argument / option | Description |
|---|---|
| `subject` | type:id, or the id when there is one subject model |
| `permission` | Name, name with the panel prefix, panel:name or a pattern |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--on=` | type:id of the assignment scope; tenant-wide without it |
| `--tenant=` | type:id, required for a panel with tenants |
| `--origin=` | The origin of the grants (manual by default) |

### `azguard:permissions:show`

Show the roles and permissions an AzGuard subject holds.

```
azguard:permissions:show [--panel [PANEL]] [--on [ON]] [--tenant [TENANT]] [--json] [--] <subject>
```

| Argument / option | Description |
|---|---|
| `subject` | type:id, or the id when there is one subject model |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--on=` | type:id of an assignment scope |
| `--tenant=` | type:id, required for a panel with tenants |
| `--json` | Print JSON |

### `azguard:roles:grant`

Grant an AzGuard role to a subject.

```
azguard:roles:grant [--panel [PANEL]] [--on [ON]] [--until [UNTIL]] [--field [FIELD]] [--tenant [TENANT]] [--origin [ORIGIN]] [--] <subject> <role>
```

| Argument / option | Description |
|---|---|
| `subject` | type:id, or the id when there is one subject model |
| `role` | Role key, panel:key or code role class |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--on=` | type:id of the assignment scope; tenant-wide without it |
| `--until=` | ISO-8601 expiry |
| `--field=` | A grant field as key=value; repeat for more |
| `--tenant=` | type:id, required for a panel with tenants |
| `--origin=` | The origin of the grant (manual by default) |

### `azguard:roles:rename-key`

Move grants of a former AzGuard role key to its current key.

```
azguard:roles:rename-key [--panel [PANEL]] [--tenant [TENANT]] [--dry-run] [--force] [--] <role> <new>
```

| Argument / option | Description |
|---|---|
| `role` | The former key, or panel:key |
| `new` | The current key, panel:key or the code role class that lists the former key |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--tenant=` | type:id, required for a panel with tenants |
| `--dry-run` | Show what would move without writing |
| `--force` | Rename in production |

### `azguard:roles:revoke`

Revoke an AzGuard role from a subject.

```
azguard:roles:revoke [--panel [PANEL]] [--on [ON]] [--tenant [TENANT]] [--origin [ORIGIN]] [--] <subject> <role>
```

| Argument / option | Description |
|---|---|
| `subject` | type:id, or the id when there is one subject model |
| `role` | Role key, panel:key or code role class |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--on=` | type:id of the assignment scope; tenant-wide without it |
| `--tenant=` | type:id, required for a panel with tenants |
| `--origin=` | The origin of the grants (manual by default) |

## Dynamic permissions

### `azguard:permissions:create`

Create a dynamic AzGuard permission.

```
azguard:permissions:create [--panel [PANEL]] [--label [LABEL]] [--group [GROUP]] [--tenant [TENANT]] [--] <name>
```

| Argument / option | Description |
|---|---|
| `name` | Name of the permission, or panel:name |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--label=` | The label |
| `--group=` | The group |
| `--tenant=` | type:id, required for a panel with tenants |

### `azguard:permissions:delete`

Delete a dynamic AzGuard permission and its grants.

```
azguard:permissions:delete [--panel [PANEL]] [--tenant [TENANT]] [--force] [--] <name>
```

| Argument / option | Description |
|---|---|
| `name` | Name of the permission, or panel:name |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--tenant=` | type:id, required for a panel with tenants |
| `--force` | Delete in production |

## Diagnostics and maintenance

### `azguard:audit:prune`

Delete AzGuard audit journal rows older than the retention.

```
azguard:audit:prune [--panel [PANEL]] [--before [BEFORE]] [--force]
```

| Argument / option | Description |
|---|---|
| `--panel=` | Only this panel |
| `--before=` | ISO-8601; delete only rows older than this as well |
| `--force` | Delete in production |

### `azguard:explain`

Explain one AzGuard authorization decision with secrets redacted.

```
azguard:explain [--panel [PANEL]] [--tenant [TENANT]] [--context [CONTEXT]] [--json] [--] <subject> <permission>
```

| Argument / option | Description |
|---|---|
| `subject` | type:id |
| `permission` | The permission in any accepted form: posts.view, admin.posts.view or admin:posts.view |
| `--panel=` | The panel; otherwise the panel resolver decides |
| `--tenant=` | type:id of the tenant, required for a panel with tenants |
| `--context=` | type:id of an assignment scope |
| `--json` | Print JSON |

## Filament

### `azguard:filament:generate`

Create the permission enums of the resources, pages and widgets of a Filament panel.

```
azguard:filament:generate [--filament-panel [FILAMENT-PANEL]] [--panel [PANEL]] [--authority [AUTHORITY]] [--with-policy] [--only [ONLY]] [--force] [--dry-run]
```

| Argument / option | Description |
|---|---|
| `--filament-panel=` | The Filament panel; the default one when omitted |
| `--panel=` | The panel directory of the AzGuard panel, such as Admin; the guard panel of the plugin when omitted |
| `--authority=` | Who decides the permissions: grants or policy |
| `--with-policy` | With grants, also create the policy of each enum and bind it with #[PolicyFor] |
| `--only=` | Only resources, pages, widgets, or a class |
| `--force` | Overwrite the files when they exist |
| `--dry-run` | Print what would be created and write nothing |
