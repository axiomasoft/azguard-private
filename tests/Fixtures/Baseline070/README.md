# Baseline 0.7.0 fixtures

Executable evidence of AzGuard 0.7.0 (PLAN1, phase P1). Every fixture here was generated from the unchanged code of
the baseline commit `09612160c98ca6133c894a8f90f5653b9dac6b8f` (tag 0.7.0; `git diff --quiet <commit> -- packages/`
held at generation). Later phases change a fixture only deliberately, in the commit that changes the behavior.

## Regenerating

A fixture is written only in writer mode, and only from the 0.7.0 code:

```bash
AZGUARD_BASELINE_WRITE=1 vendor/bin/pest tests/Regression/Baseline070 tests/Feature/Console/CommandsSnapshotTest.php \
    tests/Feature/Configuration/ConfigKeysTest.php
```

A normal run is read-only and fails when a fixture is missing. The only placeholder is `<ulid>` for a generated
ULID (`Formats\FormatSnapshot::normalised()`); timestamps come from the fixed test clock.

## Contents (I17, blocking now)

| Fixture | Test | Pins |
|:--|:--|:--|
| `Formats/exceptions.json` | `ExceptionsSnapshotTest` | class, parent, abstract/final and code of every exception; literal `invalid_configuration.<check>` codes |
| `Formats/events.json` | `EventsSnapshotTest` | `EventType` cases and strings; class, envelope, data, tenant, actor and `state` keys per event |
| `Formats/audit-rows.json`, `Formats/audit-journaled-types.json` | `EventsSnapshotTest` | the journal row of every event (columns, payload equal to `toArray()`); the event types the Audit plugin journals |
| `Formats/resources.json`, `Formats/reserved-plugin-prefix.json` | `ResourcesSnapshotTest` | publish tags, stub path, migration basenames, named-storage migration pattern, aliases, namespaces, hidden context key, plugin id, system actor type, `storage_state.schema`; P2/P3 identities as absent |
| `Formats/config-defaults.json` | `ConfigKeysTest` | defaults of every key of `azguard.php` and `azguard-filament.php`; environment names; `defaults.gate.mode` present, retired by P2 |
| `../Console/commands.json` | `CommandsSnapshotTest` | synopsis and exit code of every driven path per command |
| `Formats/doctor-checks.json`, `Formats/doctor.json` | `DoctorSnapshotTest` | check classes, keys, emitted finding ids; `azguard:doctor --json` fields, severities, exit codes (document on SQLite) |
| `Formats/explain.json` | `ExplainSnapshotTest` | `azguard:explain --json` shape, decision objects and step sequence for allow, policy denial and missing grant |
| `api-manifest/core.json`, `api-manifest/filament.json` | `InventoriesSnapshotTest` | byte copies of the 0.7.0 API manifests (sha256 checked; `git show` compared when the commit is in the clone) |
| `serializable-fqcn.json` | `InventoriesSnapshotTest` | types a host may serialize, with their type-map target |
| `Formats/grant-field-names.json` | `InventoriesSnapshotTest` | which grant-field names `Schema\Field` accepts |
| `Formats/scopes-discovery.json` | `InventoriesSnapshotTest` | what folder discovery collects from a `Scopes/` folder |

## azg_ grant fields

At 0.7.0 `AzGuard\Schema\Field` rejects only the prefixes `tenant_`, `subject_`, `context_` and `actor_` (and names
outside `[a-z][a-z0-9_]{0,63}`). `azg_region` and `azg_` are **accepted**, so a 0.7.0 application may already declare a
grant field with the `azg_` prefix. The P2 upgrade map needs a rename path for such fields.

## Scopes/ folder discovery

`discovery.scopes` (default folder `Scopes`) makes `Sources\Folder\PanelDiscovery` collect every class of the folder
that implements `Contracts\Scopes\AssignmentScopeDefinition`. Its consumers at 0.7.0:

- `Configuration\AzGuardConfig` — `DISCOVERY_KEYS` / default folder name `Scopes`; `Laravel\Console\Scaffold\Layout`
  reads the folder names for the generators.
- `Sources\Folder\PanelDiscovery` and `Sources\Folder\DiscoverySnapshot` — collect the classes and carry them as
  `scopes` in the discovery payload, which `Panels\PanelRegistry` stores in the catalog cache.
- `Sources\PanelSources::scopeDefinitions()` — instantiates them and binds them, with the scopes of the roles, to every
  `RelationSource` of the panel. This is the only behavioral consumer.
- `Diagnostics\Checks\DiscoveryCached` — compares the `scopes` part of the cached and the live discovery.

A discovered definition does **not** become a panel scope definition (`Panel::scopeDefinitions()` is empty for a panel
without a scope declaration; `Formats/scopes-discovery.json`). `AzGuardServiceProvider`
(`defaults.scopes.resolvers`), `Authorization\Visibility` and `Authorization\Pipeline\Stages\AuthorityStage`
(the compiled `scopes` of a role) read scope data, but not the result of folder discovery.

## Findings against knowledge/identities

The snapshots keep the code value; each difference is logged in the plan (PLAN1 P1.2, `plan.py events`).

| Identity | knowledge/identities | 0.7.0 code |
|:--|:--|:--|
| system actor type | `azguard:system` | `azguard.system` (`ActorRef::SYSTEM_TYPE`) |
| reserved `azguard/*` plugin id prefix | frozen | not enforced: a host plugin with id `azguard/host-plugin` is accepted |
| `InvalidConfigurationException` code | — | `invalid_configuration`, or `invalid_configuration.<check>` |
| exit codes "all commands through `CommandSupport::attempt()`" | 2 for input/identity errors | no `CommandSupport`; `InteractsWithAzGuard::attempt()` is not used by `explain` (malformed or unknown subject exits 1), `storage:migration` (unknown storage exits 1), the generators, `doctor`, `install`, `stubs`, `catalog:cache/clear` |
| doctor checks | 26 rows | also `Filament\Diagnostics\FilamentDefinitionsCheck` (`filament.definitions`, ids `filament.missing_definition`, `filament.stale_definition`); `CatalogCollisions` and `ConfigValid` emit their key both literally and through `key()` |
| explain `not_applicable` | a decision kind to snapshot | no 0.7.0 path produces it: `Decision::notApplicable()` has no caller |

Serializable types whose type-map row moves or retires them are listed under `reported` in `serializable-fqcn.json`
with a note; the published ones among them (`Scopes\AssignmentScopePhase` moved to `Context\`, `Panels\GateMode` and
`Exceptions\PluginDependencyMissingException` retired) need an owner decision or an upgrade-map entry.
