# P1 review (P1.6)

- Commit reviewed: `09aa5696caaaf5e1e4151d6ac0af2d736423e5ab` (round 2; `packages/` equal to baseline `09612160c98ca6133c894a8f90f5653b9dac6b8f`)
- Date: 2026-10-11
- Owner request: round 1 «ревью на codex gpt-6.1-sol high и одно на grok 4.7»; round 2 «Делай все, что нужно, чтобы максимально качественно закрыть пункт» (reopen after the round-1 RED was fixed)
- Reviewers (independent, read-only, same prompt `artifacts/reviews/P1/round2/prompt.md`, neither saw the other's verdict):
  - `codex/gpt-6.1-sol/high` — `codex exec -s read-only`, codex-cli 0.162.1, session `01a12a89-bf3e-7473-a930-22938b7a5027`, working shell (41 commands); verdict `artifacts/reviews/P1/round2/codex-gpt-6.1-sol.md`
  - `grok/grok-4.7/high` — grok 1.0.50 headless, `--sandbox review` (project profile: read-only, child network for the mAInd hook), tools `run_terminal_command,read_file,grep,list_dir`, resolved model `grok-4.7-build`, session `01a12a89-c64d-7b22-b941-53e3327805ca`, 47 turns, working shell; verdict `artifacts/reviews/P1/round2/grok-4.7.md`
- Review registrar and finding verification: `claude-code/opus-5-5/high`
- Acceptance executor and closure: `codex/gpt-6.1-sol/high`, session `01a12b41-068b-77e2-b8fa-ad1c03dbfd37` (model/effort, cwd, approval `never` and sandbox `danger-full-access` observed in the session runtime journal)
- Acceptance candidate: HEAD `38dc04dc8b410afdd769aaa26ba1e29f221d6d79`; difference from reviewed `09aa569` is test infrastructure and its documentation only: `.github/workflows/tests.yml`, `docker-compose.yml`, `DEVELOPMENT.md`, `docs/guides/testing.md`, `docs/reference.md`. `packages/` and `tests/` unchanged.

Verdict: GREEN

Both reviewers returned GREEN independently with no findings; each confirmed F1–F6 fixed. Acceptance V1, V2, V3 (pgsql, mysql, mariadb), V4 and V6 all exited 0 on `38dc04dc8b410afdd769aaa26ba1e29f221d6d79`; no skipped or unavailable check is counted as green. V5 validates this completed verdict file, with its actual exit recorded separately.

## Round 2 findings

none

## Round-1 findings — status at `09aa569`

| id | item | fixed in | status | evidence |
|---|---|---|---|---|
| F1 | P1.2 | `639d34e` | fixed (codex, grok) | `ExplainSnapshotTest.php:28-34` shapes every list element; `explain.json` pins contribution (`source, origin, role, permission, tenant, context, expires_at, fields`) and policy (`message, status, code`) detail keys |
| F2 | P1.2 | `639d34e` | fixed (codex, grok) | `InventoriesSnapshotTest.php:99-121` asserts `target`/`action`/`owner` of every listed FQCN against `Ownership::fromOracle()` and the phase named in each reported note against the row's removing phase; notes at `serializable-fqcn.json:1116`, `:1136` say P2; all 126 rows match the oracle |
| F3 | P1.2 | `639d34e` | fixed (codex, grok) | `ResourcesSnapshotTest.php:82-105` checks `<date>_create_azguard_<storage>_audit_log.php` against the files `azguard:storage:migration` actually writes; `resources.json` stores `false` (P3) |
| F4 | P1.1 | `09aa569` | fixed (codex, grok) | `Dataset.php:193-194` prunes `--panel=crm` only; `PopulatedDatabaseTest.php:171-173` requires a grant expired before `PRUNE_CLOCK`, one after it and one NULL; all 16 dumps keep the expired ops `operator` grant, headers record `09612160…` |
| F5 | P1.2 | `639d34e` | fixed (codex, grok) | `FormatSnapshot::assertBaselineTree()` guards `FormatSnapshot::compare`, `Writer::write`, `ParityLedger::compare` and the `commands.json` rewrite; codex also confirmed no untracked files under `packages/` (`git ls-files --others --exclude-standard packages/`) |
| F6 | P1.2 | `639d34e` | fixed (codex, grok) | `ResourcesSnapshotTest.php:79-81` `ksort`s `storage_state.schema`; keys and values unchanged |

Round-1 V3 MySQL failures, resolved:

| test | resolution |
|---|---|
| `ResourcesSnapshotTest.php:108` | F6, fixed in `639d34e` |
| `DoctorCommandTest.php:101` (order-dependent) | Attributed: `CommandsSnapshotTest` (P1.2) wrote the catalog cache to the shared testbench `bootstrap/cache/azguard.php`; fixed in `639d34e` (re-resolve `AzGuardConfig`/`CatalogCache` on the scratch path); stale cache file removed by the owner |
| `NegativeFixturesTest.php:105` "cancelled change leaves a row" | 0.7.0 defect outside P1: `ContractWorld::rowCounts()` (`packages/core/src/Testing/Contracts/ContractWorld.php:123-141`) lists tables without a schema, so on a MySQL server holding other databases it drops every count and the check compares two empty lists (false green elsewhere, red here). Recorded for P2 in `log.jsonl` with a fix plan; both reviewers confirmed the attribution. The stale databases `azguard_acceptance_legacy_test`, `azguard_acceptance_probe_test` (AZGUARD-V1 acceptance, 2026-10-10) were dropped by the owner. |

## Areas found sound by both reviewers (round 2)

- Baseline integrity: `packages/` equals `09612160…` at HEAD and at both fix commits; dump headers record the baseline; API baseline copies and the D14 oracle (`target-map.sha256`) match.
- Allowlists `i2.json` (38), `i3.json` (0), `i5.json` (2), `InactiveCost/allowlist.json` (2), `i7-allowlist.json` (0): entries real, owned, owner phases P2/P3 agree with the oracle.
- Parity/I7: unsupported paths never count as agreement; the 82 I9 disagreements stay in `i9-findings-0.7.0.json`, owner P2.
- Invariant register `tests/Arch/invariants.json`: I1–I22 agree with `knowledge/architecture#invariants`; I4, I18, I20, I22 have an owner and no invented check.
- Consumer gate: `bin/consumer-fixture.sh` exit 3 fails the job; no `continue-on-error` on consumer steps; `release`/`split` need `validate`.
- Fix commits: no weakened or tautological assertion, cross-driver snapshot split or new shared-state leak.

## Commands run (executor, round 2, acceptance HEAD `38dc04d`)

| id | command | outcome |
|---|---|---|
| V1 | `export PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; git diff --quiet 09612160c98ca6133c894a8f90f5653b9dac6b8f -- packages/` | exit 0; wall 0.003s |
| V3.pgsql | `export PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; composer test:pgsql` | exit 0; wall 339.905s; 4959/4959 passed; 339.151s (Pest) |
| V3.mysql | `export PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; composer test:mysql` | exit 0; wall 391.019s; 4959/4959 passed; 390.066s (Pest) |
| V3.mariadb | `export PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; composer test:mariadb` | exit 0; wall 217.867s; 4959/4959 passed; 217.038s (Pest) |
| V2 | `export PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; composer test` | exit 0; wall 115.749s; 4895/4895 passed; 114.788s (Pest) |
| V4 | `export PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; composer test:engines:pgsql && composer test:engines:mysql && composer test:engines:mariadb` | exit 0; wall 91.376s; pgsql: 68/68 passed; 41.110s (Pest); mysql: 68/68 passed; 25.252s (Pest); mariadb: 68/68 passed; 22.635s (Pest) |
| V6 | `export PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; docker compose --profile authority-replica up -d --wait authority-primary authority-replica && composer test:redis && composer test:replica` | exit 0; wall 4.922s; redis: 2/2 passed; 0.139s (Pest); replica: 1/1 passed; 0.235s (Pest) |
| V5 | `export PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; test -s plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P1-review.md && grep -qE '^Verdict: (GREEN\|RED)' plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P1-review.md` | exit 0; completed verdict file exists and contains GREEN; final check log/timing: `artifacts/evidence/P1.6/V5.log` / `V5.json`. |

Process deviations: V3 ran sequentially per driver with a separate observed exit; all commands exported PGSQL_PORT=55432 MYSQL_PORT=53306 REDIS_PORT=26379; V6 explicitly started replica services via `docker compose --profile authority-replica up -d --wait authority-primary authority-replica`. The existing Redis service is published at 26379. Historical logs on 09aa569 were not reused as acceptance for this candidate.

Deferred to P2: `ContractWorld::rowCounts()` MySQL schema scoping, and the two pre-existing PHPStan errors in `RoutesChecks.php:106` / `AuthorizesActions.php:91` (already recorded in the journal).

## Environment disclosures (round 2)

- Supplementary `git diff --check` exited 2 solely for trailing spaces emitted by Compose in raw `V6.log`; the log is preserved unchanged. `git diff --check -- . ':!**/*.log'` exited 0. These formatting checks do not change any acceptance exit.

- Both reviewers had a working read-only shell this round. Codex `-s read-only` ran (round-1 `bwrap: loopback` failure did not reproduce). Grok needs `--sandbox review` and the tool name `run_terminal_command`; with the built-in `read-only` profile the project mAInd hook cannot reach its daemon and denies every shell call.
- Reviewers did not run composer/pest/docker by instruction; V1–V6 are executor runs.
- `plan.py` from the swissknifeman dev runtime (`packages/task/scripts/plan.py`), as in round 1.

---

## Round 1 (history, commit `9b8092e73da25185a30f438ba9458eb1f9633cef`)

Round-1 verdict: RED. Reviewers `codex/gpt-6.1-sol/high` (session `01a12924-172e-7a61-baff-b66d3bad45ec`) and `grok/grok-4.7/high` (session `01a12924-1e5e-7b32-a323-ef21c0d624fa`), verdicts in `artifacts/reviews/P1/codex-gpt-6.1-sol.md` and `artifacts/reviews/P1/grok-4.7.md`. Every finding was re-checked by the executor against the cited code.

| id | item | location | contract clause | severity | correction | reported by |
|---|---|---|---|---|---|---|
| F1 | P1.2 | `tests/Regression/Baseline070/ExplainSnapshotTest.php:35` (`baselineJsonShape`); stored `tests/Fixtures/Baseline070/Formats/explain.json` (`"detail": "[]"`) | P1.2: explain `--json` document shape frozen (I17); snapshots compared exactly | major | Shape every list element (union of keys and leaf types), not only index 0; re-freeze `explain.json` from the unchanged 0.7.0 tree so contribution/authority/policy `detail` keys are pinned. | codex, grok |
| F2 | P1.2 | `tests/Regression/Baseline070/InventoriesSnapshotTest.php:91-104`; notes `tests/Fixtures/Baseline070/serializable-fqcn.json:1116`, `:1135` | P1.2: serializable-FQCN inventory is cross-checked with the target map (D14 oracle) | major | Load `tests/Arch/Fixtures/target-map.json` and assert each listed FQCN's `target`/`action` (including `reported`) against its row; correct the notes of `AssignmentScopePhase` and `InvalidCommandInput` — both move in P2, not P3. | codex, grok |
| F3 | P1.2 | `tests/Regression/Baseline070/ResourcesSnapshotTest.php:97-104`; `tests/Fixtures/Baseline070/Formats/resources.json:62` | P1.2: every identity marked new (P2/P3) is represented explicitly as absent at 0.7.0; row `<date>_create_azguard_<storage>_audit_log.php` (new, P3) in `knowledge/identities#resources` is missing | minor | Add the generated audit-log migration pattern to `absent_at_070` with an executable absence check (owner P3). | codex, grok |
| F6 | P1.2 | `tests/Regression/Baseline070/ResourcesSnapshotTest.php:84-108` (`storage_state_schema_keys`, `storage_state_schema_default`); stored `tests/Fixtures/Baseline070/Formats/resources.json` | P1.2: frozen identities hold on every supported driver; V3 `composer test:mysql` must exit 0 | major | The snapshot records `storage_state` keys in storage order; MySQL's JSON column returns them reordered (`prefix, version, host_keys, storage_id, identity_codec`), so the test fails on MySQL (passes on SQLite/PostgreSQL). Sort keys (or compare as a set/map) before snapshotting; re-freeze from 0.7.0 and run V3 on all three drivers. | executor (V3 run) |
| F4 | P1.1 | `tests/Fixtures/Baseline070/Persistence/Dataset.php:193`; all 16 persistence dumps | P1.1 dataset: expired grants retained alongside NULL expiry | minor | Keep at least one expired grant after the prune step (or prune selectively), regenerate the dumps and assert its presence relative to `PRUNE_CLOCK`. | codex |
| F5 | P1.2 | `tests/Fixtures/Baseline070/Formats/FormatSnapshot.php:34` | P1.2: snapshots are generated from the unchanged 0.7.0 tree | minor | Before `AZGUARD_BASELINE_WRITE=1` overwrites a fixture, fail unless `git diff --quiet 09612160c98ca6133c894a8f90f5653b9dac6b8f -- packages/` is empty; same guard for the `commands.json` rewrite. | grok |

Plan-text discrepancy (not a P1.1–P1.5 defect): P1.6 V6 says the replica fixture "starts its own services", but `tests/Engines/Support/replica-fixture.sh` does not; CI (`tests.yml` job `test-replica`) and the local run start them with `docker compose --profile authority-replica up -d --wait authority-primary authority-replica`.

Round-1 executor commands (HEAD `9b8092e`): V1 exit 0; V2 exit 0 (4895 passed); V3 exit 1 (pgsql 4959/4959; mysql 4956/4959, 3 failed, 91 min; mariadb not run); V4 exit 0 (68/68 per driver); V6 exit 0 (redis 2, replica 1). Round-1 environment: neither reviewer had a working shell (codex `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`; grok read tools only); `plan-work.py` facade unusable for layout v4.
