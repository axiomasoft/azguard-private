# Format fixtures

Executable pins of the machine formats AzGuard publishes (I17): exceptions, events, journal rows, resources, configuration
defaults, console commands, doctor output, explain output and the types a host may serialize. They are **regression
guards of the current behavior**, not history: a change that alters a pinned format on purpose regenerates the fixture in
writer mode in the same commit and names every changed fixture in its plan result. An unexplained fixture change is a
defect.

## Regenerating

A fixture is written only in writer mode, on the current tree:

```bash
AZGUARD_FORMATS_WRITE=1 vendor/bin/pest tests/Regression/Formats tests/Feature/Console/CommandsSnapshotTest.php \
    tests/Feature/Configuration/ConfigKeysTest.php
```

A normal run is read-only and fails when a fixture is missing. The only placeholder is `<ulid>` for a generated
ULID (`Formats\FormatSnapshot::normalised()`); timestamps come from the fixed test clock.

## Contents (I17, blocking)

| Fixture | Test | Pins |
|:--|:--|:--|
| `exceptions.json` | `ExceptionsSnapshotTest` | class, parent, abstract/final and code of every exception; literal `invalid_configuration.<check>` codes |
| `events.json` | `EventsSnapshotTest` | `EventType` cases and strings; class, envelope, data, tenant, actor and `state` keys per event |
| `audit-rows.json`, `audit-journaled-types.json` | `EventsSnapshotTest` | the journal row of every event (columns, payload equal to `toArray()`); the event types the Audit plugin journals |
| `resources.json`, `reserved-plugin-prefix.json` | `ResourcesSnapshotTest` | publish tags, stub path, migration basenames, named-storage migration pattern, aliases, namespaces, hidden context key, plugin id, system actor type, `storage_state.schema` |
| `config-defaults.json` | `ConfigKeysTest` | defaults of every key of `azguard.php` and `azguard-filament.php`; environment names |
| `../Console/commands.json` | `CommandsSnapshotTest` | synopsis and exit code of every driven path per command |
| `doctor-checks.json`, `doctor.json` | `DoctorSnapshotTest` | check classes, keys, emitted finding ids; `azguard:doctor --json` fields, severities, exit codes (document on SQLite) |
| `explain.json` | `ExplainSnapshotTest` | `azguard:explain --json` shape, decision objects and step sequence for allow, policy denial and missing grant |
| `serializable-types.json` | `InventoriesSnapshotTest` | the types a host may serialize or store by class name: enums, concrete Kernel values, events, exceptions, `JsonSerializable` and `__serialize` classes |

The inventories that need no fixture are asserted in `InventoriesSnapshotTest` itself: the reserved grant-field names
(`azg_`, `tenant_`, `subject_`, `context_`, `actor_`) and what folder discovery collects from a `Scopes/` folder.
