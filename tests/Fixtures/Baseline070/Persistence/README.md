# Populated 0.7.0 databases (I16)

Deterministic populated databases of AzGuard 0.7.0 (PLAN1 P1.1), generated from the unchanged code of the baseline
commit `09612160c98ca6133c894a8f90f5653b9dac6b8f`. P3 proves against them that the split rewrites no row and that Audit
adoption verifies the full `audit_log` shape.

| Path | Role |
|:--|:--|
| `Legacy070Schema.php` | frozen test-side copy of the 0.7.0 DDL (create, schema-1 shape, upgrade, drop); restoring never calls production schema code |
| `Dataset.php`, `Panels/` | the one dataset, written through the public API: panel `crm` (tenants, project scopes, Audit plugin) and panel `ops` (no tenants, no Audit plugin) on one storage |
| `Scenario.php` | the application migration files per scenario, in a scratch database path |
| `Writer.php` | prepares a scenario through 0.7.0 migrations and commands, seeds, dumps (writer mode only) |
| `Restorer.php` | restores a dump through `Legacy070Schema` and raw inserts; the one row normalisation |
| `<driver>/<scenario>.json` | header, per storage: prefix, host keys, application columns, DDL and rows of the seven tables; AzGuard rows of `migrations` |

`tests/Regression/Baseline070/PopulatedDatabaseTest.php` runs on every driver: Legacy070Schema equals the production
0.7.0 DDL; each restored scenario keeps the dumped DDL (and, without application columns and with the default prefix,
`tests/Fixtures/Storage/ddl/<driver>.json`), the full `audit_log` shape, the rows and the migrations table; no
application or package-loaded migration is pending; `verifySchema()` accepts every storage.

## Scenarios

| Scenario | Prepared as | Migrations table |
|:--|:--|:--|
| `default` | `migrate` with the package-loaded core migrations | create, upgrade (batch 1) |
| `named` | `azguard:storage:migration ledger` (prefix `lgr_`), file renamed to `2026_10_10_000000_create_azguard_ledger_storage` because the generator dates it from the wall clock; dataset on `ledger` | create, upgrade, named (batch 1) |
| `published` | `vendor:publish --tag=azguard-migrations` into the application path, then `migrate`: the copies keep the basenames and run once | create, upgrade (batch 1) |
| `schema1` | populated at schema 2, turned into the schema-1 shape (no `subject_revisions`, no `panel_state.epoch`, `storage_state` version 1, upgrade migration not run) as `SubjectRevisionsTest` does, then `migrate` runs the upgrade migration through `StorageSchema::upgrade()` | create (1), upgrade (2) |

0.7.0 refuses to write into a schema-1 storage, so the schema-1 data cannot be produced by the public API directly;
after the upgrade `subject_revisions` is empty and every epoch is 0, as for a real schema-1 database.

## Regenerating

Only from the 0.7.0 code (`Writer` refuses when `packages/` differs from the baseline commit):

```bash
AZGUARD_BASELINE_WRITE=1 DB_CONNECTION=<driver> vendor/bin/pest tests/Regression/Baseline070/PopulatedDatabaseTest.php
```

A normal run is read-only and fails when a dump is missing.

## Determinism and normalisation

The clock is fixed (`Dataset::CLOCK`, `Dataset::PRUNE_CLOCK` for `azguard:grants:prune`) and every ULID the code
generates comes from one counter (`Dataset::fixUlids()`). `Restorer::rows()` is the only value normalisation: JSON
columns decoded with sorted object keys (MySQL reorders stored JSON), other values as strings, and — only when the
writer captures the generated database — `panel_state.updated_at` replaced by `Dataset::CLOCK`. A restored database is
read as stored, so a changed timestamp fails the row comparison. Regenerating a dump changes only `generated_at`.

Dumps of different drivers differ in their ULIDs: on SQLite `Storage::lockPanel()` generates an incarnation for an
`insertOrIgnore` on every lock, so the counter advances differently. Each driver's dump is deterministic.

## Findings (logged in the plan, PLAN1 P1.1)

- `panel_state.updated_at` is written from `gmdate()`, not from the application clock (`Storage::commitTouch()`, `Storage::lockPanel()`).
- `DdlSnapshot::table()` on MariaDB joins check constraints by name only and repeats the `json_valid` constraint of
  every table with the same JSON column, also in `tests/Fixtures/Storage/ddl/mariadb.json`; `Restorer::ddl()` and
  `Restorer::uniqueConstraints()` drop the repeats on both sides of a comparison.
