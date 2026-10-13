# P2 review (P2.15)

Verdict: **GREEN** — after the corrections made in this item (F1–F3). The open entries (F4, F5) are recommendations
and do not block the phase.

- Reviewer: claude-code / claude-opus-5-5 / high, 2026-10-13, read-only pass over `236e5acc..4c63b37a`
  (P2 design commit → P2.14 exit; 766 files, 344 of them tests).
- Reference: `knowledge/contracts`, `knowledge/type-map` with the oracle `tests/Arch/Fixtures/target-map.json`,
  `knowledge/p2-execution` (#conventions, #scope-order, #participant-model, #allowlists), `knowledge/architecture`
  (#scope-boundary, #extension-system), D15–D17.
- Owner instruction for this run (2026-10-13): «длинные прогоны не запускай, исправляй концептуальные проблемы, а
  дальше промт для новой сессии для sonnet medium». Conceptual findings are therefore corrected in P2.15 itself
  (logged as a deviation from the item's read-only rule) instead of through reopened items; full-suite, driver and
  engine runs are handed to a follow-up session.

## Findings

| id | item | file:line | rule | severity | fix |
|:--|:--|:--|:--|:--|:--|
| F1 | P2.10 | `packages/core/src/Contracts/Scopes/QueryableAssignmentScopeEvaluator.php:27` (at `4c63b37a`) | p2-execution#conventions 1 (reference wins; an amendment needs the owner); contracts#scopes | high | `eligibleContexts()` carried a 4th parameter `Builder $universe`, an unguarded executable builder handed to an `Spi(Implement)` implementer, recorded only as a review note of P2.10. **Fixed here (D18):** reference signature restored; core (`ResourceContextMapping`) specializes a refused global runtime once per owner context with its concrete runtime (budget 1000, `scope_runtime_budget`), each answer on its own guarded group, attached only when all are exact; the module (`ScopeVisibility`) returns false instead of enumerating. Manifest regenerated. |
| F2 | P2.11 | `packages/core/src/Panels/PanelCompiler.php:260`, `packages/core/src/Changes/ChangePipeline.php:510` (at `4c63b37a`) | contracts#rules: "class-strings are resolved per operation through the container" | medium | A participant given as a class name was built once at compile and reused by every operation of the worker (all other slots — pipes, hooks, restrictions, conditions — resolve per operation). **Fixed here:** the panel keeps the declared class name (built at compile only to read `tables()`); `ChangePipeline::participate()` builds it per operation and rejects a binding that is no `ChangeParticipant` (`DefinitionException`, operation rolled back). |
| F3 | P2.10 | `packages/core/src/Scopes/Internal/Query/ScopeVisibility.php:58` (at `4c63b37a`) | p2-execution#conventions 9 (every new rule gets a negative test) | low | The `scope_runtime_budget` rule had only a positive test. **Fixed here** with F1: over-budget owner contexts raise `scope_runtime_budget`; refused concrete context raises `scope_query`; refused global runtime is specialized; an expressible global runtime is not. |
| F4 | P2.12 | `packages/core/src/Kernel/Decision/StateToken.php:40` | p2-execution#conventions 1; contracts#tokens lists `panel()`, `equals()`, `toString()`, `fromString()` only | low | `StateToken::of()` / `CodeStateToken::of()` stay supported (needed by `FencesReads` implementers that build the token their `state()` returns), recorded only as an assumption. Recommendation: owner confirms and the amendment is recorded in contracts#tokens (no code change expected). |
| F5 | P2.14 | `docs/upgrade.md:52`, `:56`, `:57`, `:84-87` | p2-execution#conventions 8 (at most one sentence of explanation) | low | Four bullets carry two to four sentences (scope-mode settling, `membership.configured`, scope membership timing, `ConfigSections` registration with `singletonIf`). Recommendation: shorten when P3 touches the file; content is correct. |

## Area results

- **Signatures** — every type in `knowledge/contracts` (markers, entry, plugins, panel-builder, panel, hooks,
  participants, scopes, scopes-plugin, storage, schema, config, audit, tokens, tenancy) compared with
  `packages/core/api-manifest.json`: identical names, parameter names, types, defaults and publication after F1.
  `AuditPlugin` stays at `Plugins\Audit` (oracle row `move / P3`, conventions 3). Additions beyond the reference
  with an owner decision: `AssignmentScopeSelection::inEveryContext()` (P2.10, owner answer 2026-10-12).
- **Placement / markers / D16 / D17** — covered by `tests/Arch` against the pinned oracle (markers, enum tags,
  Internal namespaces, interim paths, autoload owners) — green. No `class_alias`, `@deprecated` or forwarding
  members in `packages/*/src`. Filament uses `GateBridge` (Api) and `PanelSchema::writer()`; no `DatabaseSource`,
  `CurrentContext` or `isDynamic()` of core.
- **Scope paths** — `BoundaryStage::resolve()/structural()/decide()`, `DirectoryResolver` (shared by
  `ChangeValidator` write admission) follow #scope-order: tenant → mode → declared alias → `resolve()` →
  ref/tenant/record verification (+ target tenant membership on write) → evaluator; evaluator exception →
  `context_filter_error`, false → deny / `AssignmentScopeNotAcceptedException`. `i9-findings-0.7.0.json` holds no
  finding; parity suites green.
- **Participant model** — events built once per change result and shared by `ChangeRecord` and the after-commit
  dispatch; participants run in declaration order inside `writer->transaction()`; `OperationRows` checks the
  window, declared tables and scalar values; compile rejects core tables, shared tables, non-participants and a
  panel without the writer. Interfering pairs covered by `ChangeParticipantsTest` (retry, rollback, no-op, nested
  host transaction) and the engine race suites (driver evidence of P2.11–P2.13 on `6943da92`/`f4404a06`).
- **Tests** — no skipped/todo additions; deleted files are fixtures of retired API (`StoresGrants` writers,
  `DependsOnPlugins`, `boot()`); removed assertions observe retired behavior (`GateMode`, `gate.mode` doctor
  check, `dependencies()`, ledger clock token of a removed writer fixture); renamed checks keep or strengthen
  their assertions.
- **Allowlists** — `i2` 18, `i4` 11, `i5` 3, InactiveCost 1 entries, all `owner_phase: P3`; `i3`, `i7` empty.
- **Migration instruction** — 55 `Find → Replace` bullets cover every removed/renamed supported symbol checked by
  `MigrationGuideTest`; brevity see F5.

## Checks run in this session (PHP 8.4)

- `pest tests/Arch tests/Feature/Parity tests/Feature/InactiveCost tests/Regression/Baseline070 tests/Feature/Visibility
  tests/Feature/Scopes tests/Feature/Changes tests/Feature/Plugins tests/Contracts --fail-on-skipped`: 911/912, the
  one failure (exception snapshot gained a check id from the first F2 draft) fixed, then
  `tests/Regression/Baseline070 tests/Feature/Changes tests/Feature/Plugins/Audit`: 254/254.
- `php bin/api-manifest.php --check` 0; pint, rector (src) and phpstan (changed src files) clean.
- Not run here (owner instruction): `composer test`, `composer test:types`, full `composer analyse`, drivers and
  engine suites — handed to the follow-up session together with the P2.14 acceptance re-run.

## Checks run in the follow-up session

Clean worktree (detached, `composer install` from the main `composer.lock`), PHP 8.4 via PATH shim, drivers on ports
25432/23306/23307. Owner working-tree edits were not part of the run. All green. Rows a–c, d-V1, d-V4, d-V5 and e-* ran
at HEAD `abc937e6` (the F1/F2 fix commit); d-V2 ran at `ef52c32e`. `ef52c32e` and `a52e3db5` add only plan artifacts:
`git log abc937e6..a52e3db5 -- packages tests bin docs composer.json composer.lock` is empty, so the code under test is
identical at all three commits.

| Check | Command | Result | Log |
|---|---|---|---|
| a | `composer test` | passed, 4734 tests, 399544 assertions | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-a-test.txt` |
| b | `composer test:types` | 99.9 % (min 98) | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-b-types.txt` |
| c | `composer analyse && lint:check && refactor:check && api:check` | phpstan 0 errors, pint, rector 0, api manifest clean | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-c-static.txt` |
| d-V1 | `pest tests/Arch/MigrationGuideTest.php tests/Arch/InvariantRegisterTest.php --fail-on-skipped` | 10/10 | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-d-v1.txt` |
| d-V2 (`ef52c32e`) | allowlists owner-P3 only (`php -r` from P2.14 V2) | exit 0; the command prints nothing, so this log is empty by design (like P2.14 V2.log) — re-run with a transcript below | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-d-v2.txt` |
| d-V4 | `pest tests/Arch --fail-on-skipped` | 166/166 | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-d-v4.txt` |
| d-V5 | `pest tests/Feature/Parity tests/Feature/InactiveCost tests/Regression/Baseline070 --fail-on-skipped` | 42/42 | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-d-v5.txt` |
| e-pgsql | `composer test:db -- pgsql --full --engines` | 68/68, 2656 assertions (PostgreSQL 16.15) | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-e-pgsql.txt` |
| e-mysql | `composer test:db -- mysql --full --engines` | 68/68, 2656 assertions (MySQL 8.4.11) | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-e-mysql.txt` |
| e-mariadb | `composer test:db -- mariadb --full --engines` | 68/68, 2714 assertions (MariaDB 10.11.19) | `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/artifacts/reviews/P2/P2.15/followup/log-e-mariadb.txt` |

Re-checks in a second follow-up pass, clean worktree at `a52e3db5` (PHP 8.4 shim; `pest --list-tests` and runs, logs in
`artifacts/reviews/P2/P2.15/followup/`):

| Check | Command | Result | Log |
|---|---|---|---|
| f-list-test | `pest --list-tests --exclude-group=engines --exclude-group=redis --exclude-group=replica --exclude-group=extended` (the `composer test` filter) | 4734 tests — the count of the run in row a; includes `ScopeQueryTest` (10), `ChangeParticipantsTest` (29), `ParityPropertyTest` (8); no `tests/Engines` class | `log-f-list-test.txt` |
| f-list-engines | `pest --list-tests --group=engines` (the `test:db -- <driver> --engines` filter) | 68 tests in 19 classes of `tests/Engines` — the count of rows e-*; `ReplicaLagTest` (group `replica`) is not among them | `log-f-list-engines.txt` |
| f-d-v2 | P2.14 V2 command, transcript | exit 0 | `log-f-d-v2.txt` |
| f-replica | `bash tests/Engines/Support/replica-fixture.sh` (`composer test:replica`), `authority-replica` compose profile (PostgreSQL 16 primary 25433 + replica 25434) | 1/1, 15 assertions (`ReplicaLagTest`) | `log-f-replica.txt` |
| f-redis | `pest --ci --fail-on-skipped --group=redis` (`composer test:redis`) | 2/2, 26 assertions (`RedisStoreTest`, Redis on 26379) | `log-f-redis.txt` |

Correction of the earlier wording: `composer test` includes `ScopeQueryTest`, `ChangeParticipantsTest` and
`ParityPropertyTest` (confirmed by the listing above). The engine runs cover `tests/Engines/*` **except**
`ReplicaLagTest`, which belongs to `composer test:replica`, and `Support/` holds no tests.

`composer test:redis` and `composer test:replica` are not part of the P2.15 acceptance; both were run for completeness (rows
f-redis, f-replica) and neither touches F1/F2. `composer test:extended` (group `extended`, scale tests of nightly CI) was not
run.

## Research for F1

Query and answer: `artifacts/reviews/P2/P2.15/f1-perplexity-query.txt`, `f1-perplexity.md`. Sources: Cerbos
PlanResources (principal-only conditions resolved at once, resource conditions returned as a plan —
https://docs.cerbos.dev/cerbos/latest/recipes/filtering-resources), OpenFGA ListObjects with server-side deadline
and max results (https://github.com/orgs/openfga/discussions/23), SpiceDB LookupResources (engine-side
enumeration). Decision: D18.
