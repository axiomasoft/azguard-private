# Machine formats

Everything on this page is a format that other programs read: event payloads, journal rows, doctor and explain JSON,
published resources, environment names, exit codes and the HTTP mapping. The formats are pinned by fixtures in the
test suite and follow the promise of [Compatibility](/reference/compatibility): an identity listed here is not removed
or renamed, and its value does not change, inside a major version. Additions are allowed in a minor version, so a
reader must ignore keys it does not know.

The pages for [configuration](/reference/configuration), [console commands](/reference/commands),
[events](/reference/events) and [exceptions](/reference/exceptions) list the other identities.

## Event payloads

`toArray()` of an event, and the payload of an event the audit plugin journals, have the same shape: scalars and arrays
only. Property names are camelCase in PHP and snake_case on the wire.

Every event has these envelope keys, in this order:


`event_id`, `type`, `occurred_at`, `panel`, `tenant`, `actor`, `correlation_id`, `state`.


- `tenant` holds `key`, `type` and `id`.
- `actor` holds `type`, `id` and `reason`; it is `null` when no actor is known. The actor type of work done under
  `AzGuard::actingAs()` is the constant `azguard:system`.
- `state` holds the revision the change produced: `kind`, `storage`, `panel`, `incarnation`, `version`, `generation` and
  `fingerprint`. Treat it as an opaque observation: compare it for equality, never for order. The `state` of
  `access.decided` is the state of the catalog code instead: `kind`, `panel`, `build_id` and `fingerprint`.

The keys after the envelope depend on the event type:

| Type | Class | Data keys |
|---|---|---|
| `permission.created` | `PermissionCreated` | `permission`, `label`, `group`, `description`, `fields` |
| `permission.updated` | `PermissionUpdated` | `permission`, `label`, `group`, `description`, `fields` |
| `permission.deleted` | `PermissionDeleted` | `permission`, `label`, `group`, `description`, `fields`, `removed_grants` |
| `role.granted` | `RoleGranted` | `subject`, `role`, `context`, `origin`, `expires_at`, `fields` |
| `role.revoked` | `RoleRevoked` | `subject`, `role`, `context`, `origin`, `expires_at`, `fields` |
| `role.grant_updated` | `RoleGrantUpdated` | `subject`, `role`, `context`, `origin`, `expires_at`, `fields`, `previous_role` |
| `permission.granted` | `PermissionGranted` | `subject`, `permission`, `context`, `origin`, `expires_at`, `fields` |
| `permission.revoked` | `PermissionRevoked` | `subject`, `permission`, `context`, `origin`, `expires_at`, `fields` |
| `permission.grant_updated` | `PermissionGrantUpdated` | `subject`, `permission`, `context`, `origin`, `expires_at`, `fields` |
| `grant.expired` | `GrantExpired` | `subject`, `kind`, `role`, `permission`, `context`, `origin`, `expired_at` |
| `panel.touched` | `PanelStateTouched` | `previous_version`, `reason` |
| `access.decided` | `AccessDecided` | `subject`, `permission`, `context`, `effect`, `reason`, `component` |

`removed_grants` of `permission.deleted` lists the ids of the grants removed with the permission. `previous_role` of
`role.grant_updated` is set by `azguard:roles:rename-key`. `expires_at` and `expired_at` are UTC ISO-8601 strings.
`EventType` is an open enum: a minor version can add a type.

## Audit journal rows

The audit plugin writes one row per journaled event into the `audit_log` table of the storage. A row has these columns:


`event_id`, `type`, `panel`, `tenant_key`, `tenant_type`, `tenant_id`, `subject_type`, `subject_id`, `actor_type`, `actor_id`, `actor_reason`, `correlation_id`, `payload`, `occurred_at`.


`payload` holds the full `toArray()` of the event. The tenant, subject and actor columns copy the identity out of the
payload so a journal can be queried without decoding it; the actor columns are `null` when the event has no actor. The journaled event types are:

`permission.created`, `permission.updated`, `role.granted`, `role.grant_updated`, `role.revoked`, `permission.granted`, `permission.grant_updated`, `permission.revoked`, `permission.deleted`, `panel.touched`, `role.granted`, `grant.expired`.


## Doctor

`azguard:doctor --json` prints a JSON list of findings. A finding has these fields:

`key`, `severity`, `scope`, `message`, `details`.


`severity` is `error` or `warning`. `scope` names where the finding applies: `core`, `panel:<id>`, `storage:<id>` or `plugin:<id>`, and
`details` holds the machine-readable particulars of the finding. The command exits with `0` when there is no error, `1` when there is an error finding and `2` for an
unknown panel or storage. A check that throws is reported as an error finding with the key `<key>.failed` and the exception class in `details`.

Each check has a stable key. A finding id is the key of its check unless the table names others. Messages are not a
contract.

| Check key | Severity | Reports |
|---|---|---|
| `audit.table` | error | The audit plugin has no storing source, or its `audit_log` table is missing or differs from the audit schema |
| `cache.store` | error | A panel keeps permission sets in a cache store that does not exist or cannot hold them |
| `catalog.build_id` | warning, error | `catalog.build_id` is not set (production). An error when the catalog cache file exists or a panel keeps permission sets in a persistent cache store |
| `catalog.cached` | warning | There is no catalog cache, or it does not hold the current catalog |
| `catalog.collisions` | error | Permissions created at run time collide with the permissions of the catalog |
| `config.valid` | error | The AzGuard configuration does not load, or names a storage connection that does not exist |
| `consistency.reads` | warning | `consistency.reads = default` reads decisions from a connection with read hosts |
| `fields.meta` | warning | A decision field of a grant is stored in a meta column |
| `direct_writes` | error | A grant or permission model does not extend the storage model, so its writes bypass AzGuard |
| `panels.policies` | error / warning | A permission is bound twice (error), or the catalog cache records another discovery than the folders give now (warning) |
| `grants.dead` | warning | Grants of a permission that no longer exists, or of a subject type that is not a morph alias |
| `storage.schema` | error | A storage cannot be reached, or its columns do not fit the declared host keys |
| `panels.plugins` | error | A panel plugin is declared in a way the package cannot build |
| `panels.relations` | error | The relations of a panel do not resolve |
| `panels.sources` | error | A source is not a source, two sources share an id, or more than one source stores grants |
| `panels.valid` | error | A panel id is malformed, a panel has no provider, or an accepted model is not an Eloquent model with a morph alias |
| `policies.complete` | error / warning | A policy-only permission has no policy (error), or a public policy method decides no permission (warning) |
| `roles.keys` | error | A role has no stable key, or declares a malformed or duplicate one |
| `roles.orphaned` | error / warning | Grants of a role that no longer exists |
| `routes.checks` | error | A route enters a panel that does not resolve, lacks a permission check in a strict panel, or declares a check nothing applies |
| `storage.migrated` | error | A storage is unreachable or lacks tables of the AzGuard migrations |
| `storage.sqlite` | warning | SQLite settings that break snapshot reads |
| `membership.configured` | error / warning | A tenant or assignment-scope panel requires a membership that is not configured, or inherits roles without one |
| `filament.definitions` | error / warning | `filament.missing_definition`: a Filament panel checks a permission no enum defines; `filament.stale_definition`: an enum defines permissions no Filament panel uses |
| `scopes.membership` | error / warning | The membership check of the scopes module; its findings carry the id `membership.configured` |

## Explain

`azguard:explain --json` prints one object with the keys `decision`, `panel`, `subject`, `scope`, `resource`, `now`, `state`, `steps`:

- `decision` holds `effect` (`allow` or `deny`), `reason`, `component`, `message`, `status` and `code`. A denial by a
  policy can carry the `message`, `status` and `code` the policy gave; the other denials leave them `null`.
- `steps` lists the stages of the pipeline in order. A step has `stage`, `component`, `outcome`, `detail`, `result` and
  `exception`. The stage names are `prepare`, `boundary`, `before`, `contribution`, `sources`, `superadmin`,
  `membership`, `policy`, `authority`, `restriction`, `state`, `after`, `condition` and `filter`. A stage that did not
  run has the result `skipped`.

The exit code is `0` for an allow or a deny: a denial is an answer, not an error. Secrets are redacted.

## Resources

| Resource | Identity |
|---|---|
| Publish tag `azguard-config` | `config/azguard.php => config/azguard.php` |
| Publish tag `azguard-migrations` | `database/migrations => database/migrations` |
| Publish tag `azguard-filament-config` | `config/azguard-filament.php => config/azguard-filament.php` |
| Publish tag `azguard-audit-migrations` | `modules/audit/database/migrations => database/migrations` |
| Stub publish path | `stubs/azguard`, written by `azguard:stubs` |
| Core migration | `2026_10_01_000000_create_azguard_storage`, loaded by the service provider |
| Audit migration | `2026_10_20_000000_create_azguard_audit_log`, published with the tag above, not loaded by the provider |
| Generated storage migration | `<date>_create_azguard_<storage>_storage.php`, written by `azguard:storage:migration` |
| Generated audit migration | `<date>_create_azguard_<storage>_audit_log.php`, written by `azguard:audit:migration` |
| Middleware aliases | `azguard.panel`, `azguard.can` |
| Container alias | `azguard`, the same instance as `AzGuard\AzGuard` |
| Translation namespace | `azguard` |
| View namespace | `azguard-filament` |
| Hidden queue context key | `azguard.panel`, the panel that travels with a queued job |
| Plugin id | `azguard/audit`, the audit plugin; ids with the prefix `azguard/` are reserved for AzGuard |
| System actor type | `azguard:system` |
| Storage state keys | `host_keys`, `identity_codec`, `prefix`, `storage_id`, `version`, with the defaults `prefix = azg_`, `storage_id = default`, `identity_codec = 1` and `host_keys = string` |

The migration basenames sort in the order the tables need. A change of a table, column or index ships as a new migration
with a new basename, never as an edit of a published one.

## Environment names


The package reads two environment variables, both through the configuration file: `AZGUARD_BUILD_ID`, `AZGUARD_DB_CONNECTION`. See [Configuration](/reference/configuration#environment-variables).


## HTTP mapping

AzGuard never maps a decision to a response by itself: the Gate, `azguard.can` and `authorize()` keep answering `403` for
every denial. The opt-in `DecisionResponder` gives the reference mapping:

| Decision | Status | Thrown |
|---|---|---|
| Allowed | none | nothing |
| Denied by a rule (no failure kind) | `403` | `AuthorizationException` with the reason code only |
| Failure `transient`: authority could not be read now | `503` | `DecisionFailedException`, generic message |
| Failure `contract`: host code broke its contract | `500` | `DecisionFailedException`, generic message |

A response carries no reason, component or internals of a failure. `FailureKind` is an open enum: a minor version can
add a kind, so match it with a default arm.

## Exit codes

Every command follows the rule of [Console commands](/reference/commands): `0` success, `2` input errors, `1` any other
error. These are the exit codes pinned for each command:

| Command | Exit codes |
|---|---|
| `azguard:audit:migration` | `success` = 0; `exists` = 2; `default storage` = 2; `unknown storage` = 2 |
| `azguard:audit:prune` | `success` = 0; `production without --force` = 2; `unknown panel` = 2 |
| `azguard:catalog:cache` | `success` = 0 |
| `azguard:catalog:clear` | `success` = 0 |
| `azguard:catalog:list` | `success` = 0; `missing tenant` = 2; `unknown panel` = 2 |
| `azguard:doctor` | `no errors` = 0; `error findings` = 1; `unknown panel` = 2; `unknown storage` = 2 |
| `azguard:explain` | `success` = 0; `unknown subject` = 2; `malformed subject` = 2 |
| `azguard:filament:generate` | `invalid --authority` = 2 |
| `azguard:grants:list` | `success` = 0; `unknown panel` = 2 |
| `azguard:grants:prune` | `success` = 0; `malformed --before` = 2; `unknown panel` = 2 |
| `azguard:install` | `success` = 0; `unknown host keys` = 2 |
| `azguard:make:models` | `success` = 0; `unknown storage` = 2 |
| `azguard:make:panel` | `success` = 0; `exists without --force` = 1; `name normalised to a class` = 0 |
| `azguard:make:permission` | `success` = 0; `exists without --force` = 1 |
| `azguard:make:pipe` | `success` = 0; `exists without --force` = 1 |
| `azguard:make:plugin` | `success` = 0; `exists without --force` = 1 |
| `azguard:make:policy` | `success` = 0; `exists without --force` = 1; `group without a permission enum` = 2 |
| `azguard:make:restriction` | `success` = 0; `exists without --force` = 1 |
| `azguard:make:role` | `success` = 0; `exists without --force` = 1 |
| `azguard:make:source` | `success` = 0; `exists without --force` = 1 |
| `azguard:panels:list` | `success` = 0 |
| `azguard:permissions:create` | `success` = 0; `exists` = 1; `missing tenant` = 2 |
| `azguard:permissions:delete` | `success` = 0; `unknown permission` = 1 |
| `azguard:permissions:grant` | `success` = 0; `unknown permission` = 1; `malformed --until` = 2 |
| `azguard:permissions:revoke` | `success` = 0; `missing tenant` = 2 |
| `azguard:permissions:show` | `success` = 0; `missing tenant` = 2 |
| `azguard:roles:grant` | `success` = 0; `unknown role` = 1; `unknown panel` = 2 |
| `azguard:roles:list` | `success` = 0; `unknown panel` = 2 |
| `azguard:roles:rename-key` | `dry run` = 0; `target is not a former key` = 1; `missing tenant` = 2 |
| `azguard:roles:revoke` | `success` = 0; `unknown panel` = 2 |
| `azguard:sources:list` | `success` = 0 |
| `azguard:state:reset` | `success` = 0; `unknown panel` = 2; `production without --force` = 2 |
| `azguard:storage:migration` | `success` = 0; `default storage` = 2; `unknown storage` = 2 |
| `azguard:stubs` | `success` = 0; `published again without --force keeps them` = 0 |
