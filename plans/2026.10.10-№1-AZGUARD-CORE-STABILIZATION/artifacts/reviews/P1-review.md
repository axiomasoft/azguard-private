# P1 review (P1.6)

- Commit reviewed: `9b8092e73da25185a30f438ba9458eb1f9633cef` (clean tree; `packages/` equal to baseline `09612160c98ca6133c894a8f90f5653b9dac6b8f`)
- Date: 2026-10-11
- Owner request: «ревью на codex gpt-6.1-sol high и одно на grok 4.7»
- Reviewers (independent, read-only, same prompt, neither saw the other's verdict):
  - `codex/gpt-6.1-sol/high` — `codex exec -s read-only`, codex-cli 0.162.1, session `01a12924-172e-7a61-baff-b66d3bad45ec`
  - `grok/grok-4.7/high` — grok 1.0.50 headless `-p`, tools `read_file,grep,list_dir`, resolved model `grok-4.7-build`, session `01a12924-1e5e-7b32-a323-ef21c0d624fa`, 42 turns
- Executor (registrar, acceptance runs, finding verification): `claude-code/opus-5-5/high`

Verdict: RED

Both reviewers returned RED independently. Every finding below was re-checked by the executor against the cited code.

## Findings

| id | item | location | contract clause | severity | correction | reported by |
|---|---|---|---|---|---|---|
| F1 | P1.2 | `tests/Regression/Baseline070/ExplainSnapshotTest.php:35` (`baselineJsonShape`); stored `tests/Fixtures/Baseline070/Formats/explain.json` (`"detail": "[]"`) | P1.2: explain `--json` document shape frozen (I17); snapshots compared exactly | major | Shape every list element (union of keys and leaf types), not only index 0; re-freeze `explain.json` from the unchanged 0.7.0 tree so contribution/authority/policy `detail` keys are pinned. | codex, grok |
| F2 | P1.2 | `tests/Regression/Baseline070/InventoriesSnapshotTest.php:91-104`; notes `tests/Fixtures/Baseline070/serializable-fqcn.json:1116`, `:1135` | P1.2: serializable-FQCN inventory is cross-checked with the target map (D14 oracle) | major | Load `tests/Arch/Fixtures/target-map.json` and assert each listed FQCN's `target`/`action` (including `reported`) against its row; correct the notes of `AssignmentScopePhase` and `InvalidCommandInput` — both move in P2, not P3. | codex, grok |
| F3 | P1.2 | `tests/Regression/Baseline070/ResourcesSnapshotTest.php:97-104`; `tests/Fixtures/Baseline070/Formats/resources.json:62` | P1.2: every identity marked new (P2/P3) is represented explicitly as absent at 0.7.0; row `<date>_create_azguard_<storage>_audit_log.php` (new, P3) in `knowledge/identities#resources` is missing | minor | Add the generated audit-log migration pattern to `absent_at_070` with an executable absence check (owner P3). | codex, grok |
| F6 | P1.2 | `tests/Regression/Baseline070/ResourcesSnapshotTest.php:84-108` (`storage_state_schema_keys`, `storage_state_schema_default`); stored `tests/Fixtures/Baseline070/Formats/resources.json` | P1.2: frozen identities hold on every supported driver; V3 `composer test:mysql` must exit 0 | major | The snapshot records `storage_state` keys in storage order; MySQL's JSON column returns them reordered (`prefix, version, host_keys, storage_id, identity_codec`), so the test fails on MySQL (passes on SQLite/PostgreSQL). Sort keys (or compare as a set/map) before snapshotting; re-freeze from 0.7.0 and run V3 on all three drivers. | executor (V3 run) |
| F4 | P1.1 | `tests/Fixtures/Baseline070/Persistence/Dataset.php:193`; all 16 persistence dumps | P1.1 dataset: expired grants retained alongside NULL expiry | minor | Keep at least one expired grant after the prune step (or prune selectively), regenerate the dumps and assert its presence relative to `PRUNE_CLOCK`. | codex |
| F5 | P1.2 | `tests/Fixtures/Baseline070/Formats/FormatSnapshot.php:34` | P1.2: snapshots are generated from the unchanged 0.7.0 tree | minor | Before `AZGUARD_BASELINE_WRITE=1` overwrites a fixture, fail unless `git diff --quiet 09612160c98ca6133c894a8f90f5653b9dac6b8f -- packages/` is empty; same guard for the `commands.json` rewrite. | grok |

Plan-text discrepancy (not a P1.1–P1.5 defect): P1.6 V6 says the replica fixture "starts its own services", but `tests/Engines/Support/replica-fixture.sh` does not; CI (`tests.yml` job `test-replica`) and the local run start them with `docker compose --profile authority-replica up -d --wait authority-primary authority-replica`.

## Areas found sound by both reviewers

- Baseline integrity: `packages/` byte-equal to baseline; dump headers record `09612160…`; API baseline copies and D14 oracle checksum match.
- Allowlists `tests/Arch/Allowlists/i2.json`, `i3.json`, `i5.json`, `InactiveCost/allowlist.json`, `i7-allowlist.json`: entries real, owned, owner phases agree with the oracle.
- Parity/I7: unsupported cases snapshotted and excluded from agreement; I9 disagreements recorded in `i9-findings-0.7.0.json` with owner P2.
- Invariant register `tests/Arch/invariants.json`: I1–I22 status and owner phase agree with `knowledge/architecture#invariants`.
- Consumer gate: `bin/consumer-fixture.sh` exit 3 fails the job; no `continue-on-error`; `release.yml` `validate` runs both consumer steps unconditionally and `release`/`split` need it.

## Commands run (executor)

| id | command | outcome |
|---|---|---|
| V1 | `git diff --quiet 09612160c98ca6133c894a8f90f5653b9dac6b8f -- packages/` | exit 0 |
| V2 | `composer test` | exit 0 — 4895 passed, 415333 assertions |
| V3 | `composer test:pgsql && composer test:mysql && composer test:mariadb` (`PGSQL_PORT=55432 MYSQL_PORT=53306`, brief local ports) | **exit 1 — not green.** pgsql 4959/4959 passed; mysql 4956/4959 (3 failed, 91 min); mariadb not run (chain stopped at the mysql failure) |
| V4 | `composer test:engines:pgsql && composer test:engines:mysql && composer test:engines:mariadb` (same ports) | exit 0 — 68/68 on each driver |
| V6 | `composer test:redis && composer test:replica` (replica services started via the compose profile) | exit 0 — redis 2 passed; replica 1 passed (real streaming replica lag, no skips) |
| V5 | `test -s …/P1-review.md && grep -qE '^Verdict: (GREEN|RED)' …/P1-review.md` | run at finish |

V3 MySQL failures:

| test | isolated run on MySQL | attribution |
|---|---|---|
| `tests/Regression/Baseline070/ResourcesSnapshotTest.php:108` | fails | P1.2 deliverable — finding F6 |
| `tests/Contracts/NegativeFixturesTest.php:105` dataset "cancelled change leaves a row" (`AssertionFailedError` not thrown) | fails (29/30) | Pre-dates P1: the test, its support code, `tests/TestCase.php`, `composer.json` and `packages/` are unchanged since `09612160`. A 0.7.0 MySQL defect outside P1's scope; it still keeps V3 red and needs an owner (P2 or a fix item). |
| `tests/Feature/Diagnostics/DoctorCommandTest.php:101` "changes nothing it reads" | passes (6/6) | Order-dependent; fails only in the full MySQL run. Not attributed: a state leak from a P1-added test or a pre-existing ordering issue. Not green. |

A first V3/V4/V6 run without the local port export targeted the host-native PostgreSQL (auth failure) and the not-yet-started replica — an executor environment error, discarded and rerun.

## Environment disclosures

- Neither reviewer had a working shell: codex's sandbox failed (`bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`) and grok was limited to read tools by design; both reviewed through file reads (codex also via Git objects). V1/V2 were not re-run by the reviewers; V3/V4/V6 were left to the executor.
- The `plan-work.py` facade is unavailable for this layout-v4 plan (plugin cache 0.28.3: `No module named 'core.provider'`; release v0.28.6 expects `plan.md`); the item ran through the swissknifeman dev runtime `packages/task/scripts/plan.py`.
