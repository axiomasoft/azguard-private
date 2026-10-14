# Review P4 (P4.9)

Reviewer: claude-code / claude-opus-5-5 / high, 2026-10-14. Reviewed tree: `a4248391` (frozen candidate C =
`3d789538`, pointer C+1 = `5ec7a794`, then plan-only commits). Reference: knowledge/p4-execution (conventions, gate,
compatibility, breaks, release-workflow, relocation, load), D24–D27.

Verdict: **GREEN** after corrections (the reviewed candidate C was RED on R1).

Conditions of the verdict:

- Owner instruction in this session: «прогоны не делай», «только ревью и исправление кода и тесты». No test, drill,
  consumer or qualification run was made. The R1 correction is checked by `php -l` and `pint --test` only; it needs
  the runs listed under "Open runs" before a tag.
- R1 changes `packages/**` after the freeze: C and C+1 do not contain it. Either C moves to the fix commit (new CI on
  `feat/1.0-candidate`, new pointer commit, gate.md rows re-pointed), or `v1.0.0` goes on C+1 as frozen and the fix
  ships in 1.0.1. This is the owner's release decision (D27).

## Findings

| id | item | file:line | rule | severity | fix |
|:--|:--|:--|:--|:--|:--|
| R1 | P4.2 (F2) | `packages/core/src/Testing/Internal/Contracts/ContractWorld.php:134-138` (at C) | Testing kit: row counts cover only the tables of the connection's current database/schema (the method's own comment, `ContractKitRegressionTest` "leaves out the tables of another schema"); supported matrix Laravel 11 × PostgreSQL | medium | Fixed (F10): on Laravel 11 the PostgreSQL list is narrowed to `current_schemas(false)`; the test's Laravel 11 expectation leaves out the foreign schema. Not run. |
| R2 | P4.3 | `tests/Arch/CompatibilityTest.php:542-545` | p4-execution#conventions item 3 / P4.1 V2: no 0.x version outside `UPGRADING.md`, `LegacyNamesTest`, `MigrationGuideTest` | low | Not changed: the names `v0.3.0`/`v0.7.0` are what #breaks requires the tag-selection test to reject (D26 "`auto` ignores `v0.*`"). The exception list of conventions item 3 (and the P4.1 V2 pathspec) should name `tests/Arch/CompatibilityTest.php`; a plan amendment for the owner. |

### R1 — Laravel 11 + PostgreSQL counts every schema (the F2 fallback)

F2 (8e434b7d, 4b130d83) made `ContractWorld::rowCounts()` work on Laravel 11, which has neither
`getCurrentSchemaListing()` nor a schema argument of `getTables()`. The fallback took `getTables()` unfiltered and keyed
every table by its bare name, with the comment that Laravel 11's list "is already … the search path (PostgreSQL)".
It is not: Laravel 10/11 `PostgresGrammar::compileTables()` selects from `pg_class`/`pg_namespace` for every schema
except `pg_catalog` and `information_schema` (checked in the 10.48 source; `PostgresBuilder::dropAllTables()` of the same
versions filters that list by `getSchemas()` for this reason). On Laravel 11 × PostgreSQL:

- tables of other schemas enter the counts, keyed by their bare name, so two tables of the same name in two schemas
  overwrite each other's count (schema-per-tenant applications): the participant contract check "holds exactly the rows
  of the attempt that committed" can compare the wrong table;
- `ContractKitRegressionTest` "leaves out the tables of another schema…" fails there (`testbench.contract_foreign`
  present) — the regression the test was written for comes back.

CI did not see it: the Laravel 11 jobs run SQLite only; the DB lanes are Laravel 12/13. MySQL/MariaDB (current
database) and SQLite (`main`) are not affected on Laravel 11.

Correction: Laravel 12+ path unchanged; on Laravel 11 the PostgreSQL list is filtered by
`select unnest(current_schemas(false))` (the effective search path, what `getCurrentSchemaListing()` describes), and
the key rule of Laravel 12+ applies (bare name for the first schema of the path, `schema.table` for the others).
The test's Laravel 11 expectation is built from `getTables()` without the foreign schema instead of `getTableListing()`.

## Scope checks

### Gate (gate.md against its evidence)

| Check | Result |
|:--|:--|
| Every run of gate.md on C | `ci-runs-3d789538….json`: Tests, Static Analysis, Code Style, Compatibility, CodeQL, Zizmor (push and dispatch), Tests (nightly) — all `headSha` = C, all `success`; run ids equal the gate.md URLs |
| C+1 | `ci-runs-5ec7a794….json`: six workflows `success` on C+1; `git show --stat 5ec7a794` outside `plans/` = `compatibility.json` only; `candidate` = C |
| Logs named by gate.md | `logs/P48-V3.log`, `P48-V10.log`, `P48-V13-api-compat.log`, `P48-V13-drill.log` exist |
| Production code after the P4.6 bench | `git diff --name-only 8c39e224 C -- packages bench` empty (as gate.md row 7 states) |
| Ten amended conditions | each row names evidence on C or C+1; Zizmor advisory and I22 = smoke suite are recorded deviations of P4.8 |

### Comparator (rule table vs code)

`bin/api-compat.php` implements every rule id of #compatibility; the code is stricter in places (a method or constant
becoming `final`, a property becoming `readonly` → `member.visibility_narrowed`; a new parent interface of an
`spi-implement` interface → `implement.method_added`). The manifest lists declared public and (for non-final types)
protected members; no supported type has an internal AzGuard parent, and every `spi-implement` interface extends only
`spi-implement` interfaces, so a method added through an ancestor is still reported.

Unlisted breaks, one or more per category (the drill in a throwaway clone was not run, owner instruction; each break was
applied in memory to the current artifacts and passed to `compatCompare()`):

| Category | Break not in the unit catalogue | Result |
|:--|:--|:--|
| type | `readonly` added to `AuthorizationEngineException` | `type.shape_changed` |
| member | property of `CheckPermission` became readonly / changed type | `member.visibility_narrowed` / `member.return_changed` |
| member | constant of `Change` removed / made protected | `member.removed` / `member.visibility_narrowed` |
| member | method `AccessEvent::context()` made final; removed | `member.visibility_narrowed`; `member.removed` |
| parameter | first parameter of an `AzGuard\AzGuard` method made by-reference | `parameter.default_changed` |
| implement | `ProvidesRoles` (spi-implement) gains parent interface `FencesReads` | `implement.method_added` |
| contracts | type-level `@template` added | `contracts.changed` |
| facade | facade method parameter renamed | `facade.changed` |
| exceptions | parent of an exception changed; an `invalid_configuration_checks` id removed | `format.changed`; `format.removed` |
| config-defaults | retired key `defaults.gate` reported present again | `format.changed` |
| commands | option became negatable; argument became array; optional argument inserted before the others | `format.changed` (each) |
| ddl | column nullability; index uniqueness; constraint definition; new table without a migration | `format.changed` ×3; `format.ddl_unmigrated` |

No break passed. Observation (no finding): the commands fixture is the Symfony synopsis, which does not show an
option's array mode; no AzGuard command has an array option today.

### Release graph

`release.yml`: tag triggers only (`v[0-9]+.[0-9]+.[0-9]+` and `-*`), no `workflow_dispatch`; jobs `tests`, `static`,
`style`, `docs`, `nightly`, `compat` call their workflows; `split.needs` and `release.needs` list all of them (release
also `split`); `contents: write` only on `release`; `split.yml` is `workflow_call` only. Every called workflow has
`workflow_call`; `docs.yml` deploys only on `main`. `compatibility.yml`: `fetch-depth: 0`, `fetch-tags: true`,
`api:check` before `api:compat`, the label reaches the guard through `env: LABELS`. The only `continue-on-error` is the
Codecov upload of `tests.yml`. Concurrency groups of the called workflows are distinct (`tests-`, `tests-nightly-`,
`compatibility-` + ref). The relocation step with a pre-release version (`1.1.0-rc.1` → `1.1.1-rc.1`) parses.
`bin/test-compat-baseline-guard.sh` covers no change, label, no label, similar labels, malformed/non-list/nested labels,
unknown ref, missing argument, and a pointer moved on main after the branch point (three-dot diff).
`ReleaseWorkflowTest` has a negative case per assertion (22 mutations).

### Fixes since the P3 exit (`ebf36ad2`)

Commits touching `packages/**`: 93c98eef (P4.1 branch aliases, item scope), 8e434b7d and 4b130d83 (F2), b552bd4e (F3) —
each in fixes.md with its failing check. No supported type, manifest, format fixture, DDL fixture or migration changed
(`git diff --stat ebf36ad2 HEAD` lists no `api-manifest.json`, `tests/Fixtures/Formats`, `Console`, `Storage/ddl`).

### Tests since the P3 exit

`git diff ebf36ad2 HEAD -- tests`: no skip, todo or `only` added; 4 `expect(` lines removed, all replaced
(InvariantRegisterTest: `['I18', 'I22']` → `[]` and the build-entry loop → a stricter "no build entry, every invariant
blocks now"; MigrationGuideTest and NoScopeDeclarationTest: messages without 0.x), 32 added. Renames of the 0.7.0 parity fixtures drop
only the `baseline` key. `tests/Arch/invariants.json`: no check lost; every check file exists; all entries
`exists`/`now`.

### D24/D25, relocation, load

- P4.1 V2 pattern on HEAD: only R2. CHANGELOG starts at `[1.0.0]`; `docs/upgrade.md` is gone; `UPGRADING.md` holds the
  AI-agent section.
- relocation.md: before/after tables agree with #relocation step 3 (one autoload owner, migration ran and nothing to
  migrate, no new published file, synopses equal to the fixture, doctor exit 0 without an `audit.table` finding, rows
  unchanged, one appended row, prune exit 0); the script asserts each (`bin/consumer-fixture.sh:396-466`). The next-patch
  version of the relocated archives is a recorded P4.4 deviation.
- load.md: six pairs, exit 0, 18/18 correctness checks, zero `*_error`; unstable stages remain (host noise; second pass
  stopped by the owner) — a recorded P4.6 deviation; gate condition 7 asks for passing checks and zero engine errors,
  which hold.

## Open runs (for a later session)

On PHP 8.4, SQLite plus PostgreSQL: `vendor/bin/pest tests/Feature/Testing/ContractKitRegressionTest.php
tests/Contracts tests/Modules/Audit/Contracts` (SQLite, Laravel 13), the same with `composer test:db -- pgsql`, and once
on Laravel 11 × PostgreSQL (CI has no such lane; a scratch app or `composer require laravel/framework:^11` in a clean
worktree). Then `composer analyse` for `ContractWorld.php`. If C moves: CI-PUSH of the new head, a new pointer commit, gate
rows re-pointed.

Recommendation (not a finding): one Laravel 11 job against PostgreSQL in `tests.yml` would cover the class of R1.
