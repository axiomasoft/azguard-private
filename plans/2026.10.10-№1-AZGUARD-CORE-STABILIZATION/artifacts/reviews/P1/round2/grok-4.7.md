I'll review P1 round 2 against the phase contract and the code, starting with the round-1 findings and the two fix commits.The two fix commits are on HEAD and `packages/` still matches the 0.7.0 baseline. Next I’ll check each round-1 finding in the code and fixtures.The fix commits are in place. Next I’ll check the fixtures, dump headers, and the checks that could still pass for the wrong reason.Verdict: GREEN

| id | item | location (file:line or fixture) | contract clause violated | severity (blocker/major/minor) | minimal correction |
|---|---|---|---|---|---|
| none | | | | | |

## Round-1 findings

| id | status (fixed / partially fixed / not fixed) | evidence (file:line, command) |
|---|---|---|
| F1 | fixed | `tests/Regression/Baseline070/ExplainSnapshotTest.php:28-34` maps every list element. `tests/Fixtures/Baseline070/Formats/explain.json` pins each step: contribution detail is `source, origin, role, permission, tenant, context, expires_at, fields`; policy denial detail is `message, status, code`. Authority steps in these three documents have an empty detail, which matches `Trace::record()` when no detail is passed. |
| F2 | fixed | `tests/Regression/Baseline070/InventoriesSnapshotTest.php:99-121` loads `Ownership::fromOracle()` (`tests/Arch/Fixtures/target-map.json`) and checks `target`, `action`, and `owner` for every listed FQCN. Reported notes must name only that row's phase. `InvalidCommandInput` and `AssignmentScopePhase` are `phase: P2` in the oracle (`target-map.json:6735`, `:8079`) and the notes at `serializable-fqcn.json:1116` and `:1136` say P2. All 11 reported rows and all 115 stable rows match the oracle. |
| F3 | fixed | `tests/Regression/Baseline070/ResourcesSnapshotTest.php:82-105` records `generated migration <date>_create_azguard_<storage>_audit_log.php (P3)` from the files `azguard:storage:migration` writes, and keeps that name out of `named_storage_migration`. `resources.json` stores `false`. The identity row in `knowledge/identities.json` is that same pattern, new in P3. `StorageMigrationCommand` writes only `create_azguard_<name>_storage.php`. |
| F4 | fixed | `tests/Fixtures/Baseline070/Persistence/Dataset.php:193-194` prunes with `--panel=crm` (`GrantsPruneCommand` honors `--panel`). `PopulatedDatabaseTest.php:171-173` requires a grant before `PRUNE_CLOCK`, one after it, and one with `expires_at` null. All 16 dumps: header `commit` is `09612160c98ca6133c894a8f90f5653b9dac6b8f`; ops role `operator` expires `2026-10-07 00:00:00`; no CRM grant has that expiry; `grant.expired` is still journaled twice. Logical grant rows match across sqlite, pgsql, mysql, and mariadb. |
| F5 | fixed | `FormatSnapshot::assertBaselineTree()` (`FormatSnapshot.php:47-54`) runs `git diff --quiet 09612160… -- packages/` and throws on any non-zero status. It is called from `FormatSnapshot::compare`, `Writer::write`, `ParityLedger::compare`, and the `commands.json` rewrite in `CommandsSnapshotTest.php:281-282`. |
| F6 | fixed | `ResourcesSnapshotTest.php:79-81` `ksort`s `storage_state.schema` before the snapshot. `resources.json` keys are `host_keys, identity_codec, prefix, storage_id, version`, with the same values as before. |

The two fix commits do not add a weakened or tautological assertion, a cross-driver snapshot split, or a new shared-state leak. `CommandsSnapshotTest` forgets `AzGuardConfig` and `CatalogCache` after pointing `catalog.cache_path` at a scratch directory (`CommandsSnapshotTest.php:87-90`). `CatalogCache` takes that path at construction, and command `handle()` methods resolve it after `beforeEach`. `PanelRegistry::freeze()` only reads the cache. Orchestra builds a new application per test, and `afterEach` deletes the scratch directory.

Known issues stay outside P1, and their attribution is right. `packages/` equals `09612160`. `RoutesChecks.php:106` and `AuthorizesActions.php:91` are still those lines. `ContractWorld::rowCounts()` (`ContractWorld.php:123-141`) calls `getTables()` with no schema and, on any throwable, drops every count for that connection. `log.jsonl` records that for P2.

## Inspected:

- `git rev-parse HEAD` — `09aa5696caaaf5e1e4151d6ac0af2d736423e5ab`. `git diff --quiet 09612160c98ca6133c894a8f90f5653b9dac6b8f -- packages/` — exit 0.
- `git show 639d34e` and `git show 09aa569` — the F1–F6 and catalog-cache edits above. The sqlite dump diff against `639d34e` adds the ops `operator` grant and lowers one subject-revision counter (`revision` 2→1, `version` 5→4), which is the ops prune no longer running. `generated_at` is `2026-10-11T06:17–06:18Z`.
- `php -l` on the nine changed PHP files — no syntax errors.
- PHP over `explain.json` — per-step `detail` shapes as in the F1 row; `step_keys` are `stage, component, outcome, detail, result, exception`.
- PHP over all 16 dumps — every header `commit` is the baseline commit; grant fingerprints match across the four drivers for `default`, `named`, `published`, and `schema1`.
- PHP over `serializable-fqcn.json` and `target-map.json` — reported-phase check and stable `target`/`action`/`owner` check, no mismatches.
- `tests/Arch/Fixtures/target-map.sha256` equals `sha256` of `target-map.json` (`ddb5d7e2…`) and that file is byte-equal to `artifacts/inventory/target-map.json`.
- `tests/Arch/invariants.json` versus `knowledge/architecture.json` `#invariants` — I1–I22, status and last named phase match `InvariantRegisterTest`'s rules; I4, I18, I20, and I22 have an owner phase and no check yet.
- Allowlists `i2.json` (38), `i3.json` (0), `i5.json` (2), `InactiveCost/allowlist.json` (2), `i7-allowlist.json` (0) — files exist, referenced text is in the file, owner phase is P2 or P3. `audit_log` entries resolve through `Ownership::TABLES` to the audit relocation phase P3.
- `ParityLedger::counts()` / `disagreements()` and `ParityMatrixTest.php:46-65` — an unsupported path is not counted as agreement; verdict disagreements must equal `i9-findings-0.7.0.json`.
- `bin/consumer-fixture.sh:54-58` exits 3 when Packagist is unreachable. `bash -c 'trap … EXIT; exit 3'` on bash 5.2.21 returns 3. `tests.yml` job `consumer-fixture` and `release.yml` `validate` run both consumer steps with no `continue-on-error`; `release` and `split` `need` `validate`. The `continue-on-error` at `tests.yml:259` is the coverage upload only.
- `ContractWorld.php:123-141`, `RoutesChecks.php:106`, `AuthorizesActions.php:91` — known issues, unchanged production code.