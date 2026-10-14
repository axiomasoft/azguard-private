# P3 review (P3.15)

Verdict: **GREEN** — after the corrections made in this item (F1–F6). The reviewed tree `902ada79` was RED (F1, F2:
a module imported a core internal and the blocking I2/I3/I4 scans could not see that file). Open entries R1–R4 are
recommendations and do not block the phase.

- Reviewer: claude-code / claude-opus-5-5 / high, 2026-10-14, read-only pass over `c505bb76..902ada79`
  (parent of P3.1 → P3.14 exit; 1268 files, 455 of them under `tests/`).
- Reference: `knowledge/p3-execution` (#conventions, #amendments, #scope-description, #module-guard, #persistence,
  #allowlists, #activation, #commands), D5, D19–D23, `knowledge/target-structure` (#rules, #scopes, #audit,
  #devtools, #tests), the register `tests/Arch/invariants.json` and the oracle `tests/Arch/Fixtures/target-map.json`.
- Owner instruction for this run (2026-10-14): «делай максимально кодовую работу, если в конце потребуется протестить
  прогоны, то напиши промт для sonnet/medium, что он запустил их и пофиксил что вылезет». As in P2.15, confirmed
  findings are corrected here (logged as a deviation from the read-only rule) instead of through reopened items;
  the full suite, driver lanes, engines, type coverage and consumer archives of the corrected tree are handed to a
  follow-up session.

## Findings

| id | item | file:line | rule | severity | fix |
|:--|:--|:--|:--|:--|:--|
| F1 | P3.1 / P3.2 | `packages/core/modules/scopes/src/Internal/ScopeConfiguration.php:11`, `:221` (at `902ada79`) | I4 (modules use only supported core types; blocking since P3.12, empty allowlist); P3 contract boundary; p3-execution#conventions 10 | high | The Scopes module imported the core internal `Panels\Internal\CanonicalMetadata` in `filterMetadata()`, whose result was discarded: it only refused filters that capture a live model/query/request/container. Core already refuses them on the same compile path (`PanelFingerprint::metadata()` fingerprints every description component, `RoleCompiler` hashes every binding component). **Fixed here:** `filterMetadata()` and the import removed; a comment names where core refuses. Negative control: re-adding the import fails `I4 (blocking)`. |
| F2 | P3.12 | `tests/Arch/Ownership.php:44-62`, `tests/Arch/BoundaryScan.php:19`, `tests/Arch/ApiUsage.php:128-139` (at `902ada79`) | I2/I3/I4 blocking with empty allowlists (P3 exit V1); target-structure#rules 1 (one owner per file) | high | The module part of the split oracle row `AzGuard\Scopes\ScopeConfiguration` (`target_path` `… + packages/core/modules/scopes/src/Internal/ScopeConfiguration.php`) has no row of its own, so `fileOwner()` returned `split`; `BoundaryScan::OWNERLESS` and `ApiUsage::i4Files()` skip such files and references, and F1 passed CI. It is the only split-only target and the only production file affected. **Fixed here:** a split part without its own row is owned by the root of its target path (`packages/core/src` → core, `packages/core/modules/<m>` → module, `packages/filament/src` → filament); new tests: a synthetic split part is owned and scanned for I2/I3, no production file resolves to `split`/`retired`, and `i4Files()` contains the file. The existing split test now expects `core` for the core part (stricter). |
| F3 | P3.13 | `tests/Fixtures/Formats/resources.json:73`, `tests/Regression/Formats/ResourcesSnapshotTest.php:109`; comments `tests/Feature/Configuration/ConfigKeysTest.php:226`, `tests/Feature/Console/CommandsSnapshotTest.php:26`, `tests/Feature/Directories/WriteEqualDirectoryTest.php:28`, `tests/Feature/Visibility/ScopeQueryTest.php:241`, `tests/Fixtures/Parity/Panels/NoScope/NoScopeCrmPanelProvider.php:14`, `tests/Arch/InvariantRegisterTest.php:10` | D23 (current-format snapshots without 0.7.0-only inventories); p3-execution#conventions 2 (nothing in tests speaks of 0.7) | medium | The P3.13 grep gate covered `packages`, READMEs and docs only. The resources snapshot kept an `absent_at_070` inventory with phase tags, and six test comments described behavior "as 0.7.0". **Fixed here:** section renamed `module_resources` without phase tags, fixture regenerated in writer mode (`AZGUARD_FORMATS_WRITE=1`, only that section changed); comments reworded. The I7/I9 parity ledgers stay (R2). |
| F4 | P3.12 | `tests/Feature/Activation/ActivationMatrixTest.php:47-58` (at `902ada79`) | p3-execution#activation row 1 ("boot, check, grant, revoke, visibility, doctor") | low | The measured round trip had no visibility call. **Fixed here:** row 1 runs `visibleTo()` inside the cost window; a tenant panel without a scope declaration answers `resource_owner_adapter` (current core rule) and resolves no module service; the control row with both modules runs the same call. |
| F5 | P3.1 | `packages/core/src/Panels/Internal/PanelCompiler.php:385-387` (at `902ada79`) | D21 ("same-alias definitions must be identical or conflict (no filter merge)") | low | The docblock of `scopeDeclaration()` still said definitions and directories are "merged"; the code (`:493-499`) implements identical-or-conflict. **Fixed here:** docblock states identical-or-conflict. |
| F6 | P3.14 | `tests/Arch/InvariantRegisterTest.php:9-10`, `tests/Arch/invariants.json` (I2, I3, I4) (at `902ada79`) | P3 exit V1 (I2–I5, I13, I15, I16 green and blocking); register field semantics | low | P3.14 moved I5/I13/I16 to `exists` and relaxed the table comparison to allow `build → exists`, but the field docblock still defined `exists` as "a check present at 0.7.0", and I2/I3/I4 (checks built in P1/P2, blocking since P3.12) stayed `build`. **Fixed here:** docblock defines `exists` as "the check runs (given as present, or delivered by its phase)"; I2/I3/I4 → `exists`. |

## Recommendations (not blocking)

| id | file:line | note |
|:--|:--|:--|
| R1 | `packages/core/src/Authorization/Internal/ReadAttempt.php:442` | The cache bypass for live automatic roles tests `instanceof FolderSource`, while `AuthorityStage:221` admits automatic roles through the P3.3 marker `EvaluatesAutomaticRoles`. Allowed by D5 (the coordinator keeps concrete-source branches), but one concept has two criteria; using the marker in both places keeps them in step. |
| R2 | `tests/Fixtures/Parity/i9-findings-0.7.0.json`, `unsupported-0.7.0.json`, `ParityLedger.php:26-28`, `NoScopeDeclarationTest.php:19-54`, `ParityMatrixTest.php:13-19` | The I7/I9 ledgers keep `0.7.0` in names and texts (deferred by P3.13 as a recorded deviation). They record findings against the baseline code; rename with P4's version metadata (D23 consequence). |
| R3 | `packages/core/modules/scopes/src/Internal/ScopeConfiguration.php` (`closure()`) | The compile-time check accepts an untyped second parameter (as #module-guard specifies), so `fn ($query, $user)` compiles and receives the runtime. D22's consequence text speaks of rejecting name-injected closures; the migration line covers the typed form. Owner may want a doctor hint or a parameter-name check; no change made (the specification wins). |
| R4 | `packages/core/composer.json:52`, `packages/filament/composer.json:37`, root `composer.json:3,14-15,42-43`, `bin/consumer-fixture.sh:19` | Version metadata still `0.7` — P4 by D23. |

## Area results

- **Placement** — module trees equal `knowledge/target-structure` (#scopes: no provider/config/table/command;
  #audit: migration, stub, `AuditServiceProvider` with publish tag and two console commands; #devtools: provider owns
  `scaffold`, 16 stubs). PSR-4 roots and `extra.laravel.providers` as in #amendments; `src/Scopes|Audit|DevTools`
  absent, `src/Plugins` holds only `PluginContext`. No `class_alias`, `@deprecated` or forwarding classes;
  every attribute carries `since: '1.0'`. TargetPlacementTest green (Arch run below). Allowlists `i2–i5`,
  InactiveCost and I7 are `[]`.
- **Boundaries** — no `AzGuard\Scopes|Audit|DevTools` reference (also double-backslash strings), module plugin id or
  `audit_log` literal in core `src`, `config`, `stubs`, `database`, `resources`; no module↔module or module↔Filament
  reference. After F1/F2 a scan of all module files finds no I4 violation.
- **D21** — every core site of #scope-description reads `ContextIdentity::describe()`: `PanelCompiler`
  (tenant owner, components without evaluator, identical-or-conflict with the same message, description directory),
  `PanelFingerprint` (`configuration` + `components`, membership/adapters only under `options`; a test proves a
  changed membership or adapter changes the fingerprint), `RoleCompiler` `filter_configuration`,
  `ResourceContextMapping`, `BatchInputs`, `SchemaBuilder` (own `scopes()` enumeration, identity check, label/directory
  order, `exactSupport` per #amendments). `ScopesPlugin::register()` refuses adapters outside its own definitions and
  non-contract membership/adapters; resolvability is the `scopes.membership` doctor finding.
- **D22** — `FilterQuery`/`FilterGuard` reproduce `PredicateBuilder`/`PredicateQuery`/`QueryGuard` line by line
  (refused `setQuery/newModelInstance/delete/forceDelete`, nested `where` and `has` re-guarded, shape before/after,
  guarded base query refusing execution and before-query callbacks); the only difference is that a guarded source's
  `guard` is not copied over the shared one (same guard either way). Closures are called `($query, $runtime)` with
  the compile-time check; negative tests cover delete, order, join, limit, `setQuery`, replacement result and typed
  name-injected closures.
- **D19/D20** — `StorageSchema` builds/drops six tables, no `upgrade()`, one core migration; `AuditSchema` reproduces
  the former audit block column by column through `IdentityColumns` (14 columns, four indexes, tenant pair
  constraint); the Audit migration is create-only on `default`, published with plain `publishes()` and never loaded;
  `azguard:audit:migration` refuses `default`, unknown storages and an existing file with exit 2; `audit.table`
  names the remedy per storage. DDL fixtures: six-table and `.audit` per driver (8 files). FreshInstallTest holds the
  five #persistence scenarios (group `database`). `JournalPruner` follows the prune model (1000-id batches, own short
  transaction, retry of 40001/40P01/1213 up to 3, no lock/mutation/pipeline, UTC cut-off as `JournalRow`);
  `JournalPruneRaceTest` covers the three concurrent pairs, the feature test the `--before` pair.
- **D5** — no concrete-source `instanceof` or import in `Pipeline/Stages`, `Internal/Query` or `Visibility.php`
  (arch test); `ReadAttempt` keeps the coordinator branches (R1).
- **Tests** — no `skip`/`todo`/`only` added since `c505bb76`. Removed by decision only: `PopulatedDatabaseTest` and
  the `Baseline070/Persistence` replays (D19), the two 0.7.0 byte-copy manifest tests (D23); moved:
  `AuditPruneCommandTest` (4 → 8 tests in `tests/Modules/Audit`), two scope property cases to
  `tests/Modules/Scopes/Properties`; `Formats/InventoriesSnapshotTest` keeps the serializable-types and reserved-prefix
  checks. Weakened outside a decision: the register comparison of P3.14 (F6, accepted with its semantics now
  stated). Changed test files: 1545 → 1592 `it()` blocks; suite 4753 (P3.1) → 4795 (P3.14).
- **Migration instruction** — `docs/upgrade.md` lines 89–133 cover D21 (no merge, adapter alias, `exactSupport`,
  `DescribesAssignmentScope`), D22 (closure signature), D19/D20 (audit installation, upgrade migration, `upgrade()`,
  `storage.migrated`), moves of formerly published types; MigrationGuideTest green.
- **D23 gate** — `packages`, READMEs and docs clean (except `docs/upgrade.md`, CHANGELOG, version metadata R4);
  tests after F3 except the parity ledgers (R2).

## Re-run of P3.14 V1–V4 (PHP 8.4, owner WIP present in the working tree)

| check | on `902ada79` | after F1–F6 |
|:--|:--|:--|
| V1 MigrationGuide + InvariantRegister | 11 passed | see V2 (both files are in `tests/Arch`) |
| V2 `tests/Arch` | 177 passed | 179 passed |
| V3 Parity, InactiveCost, Regression | 147 passed | 147 passed |
| V4 pint (`packages tests bin`), rector, api:check | passed | passed (pint after one `blank_line_before_statement` fix in the new row-1 code) |
| V4 phpstan | 1 error `trait.unused` (`InteractsWithAzGuard`), caused by the owner's uncommitted deletion of `tests/Fixtures/Testing/KitCase.php`; P3.14 verified it green on a clean HEAD worktree | the same single owner-WIP error, nothing else |

Targeted after F1–F6: `tests/Modules`, `tests/Feature/{Activation,Storage,Configuration,Console,Directories,Visibility}`
1065 passed. Not re-run here (owner instruction): `composer test`, `test:db` lanes and engines, `test:types`,
consumer archives — see the follow-up prompt in the run report.

## Final long checks on `e4190d0a` (PHP 8.4, owner WIP present in the working tree)

All long checks the previous section left open were run after P3.15; nothing failed, so no code was changed.

| # | check | result |
|:--|:--|:--|
| 1 | `composer test` | passed: 4797 tests, 395838 assertions |
| 2 | `composer test:types` | passed: type coverage 99.9 % (min 98) |
| 3 | `composer test:db -- pgsql --parallel` | passed: 762 tests, 3894 assertions |
| 3 | `composer test:db -- mysql --parallel` | passed: 762 tests, 3894 assertions |
| 3 | `composer test:db -- mariadb --parallel` | passed: 762 tests, 3894 assertions |
| 4 | `composer test:db -- pgsql --engines` | passed: 71 tests, 2670 assertions |
| 5 | `bash bin/consumer-fixture.sh core-only` | passed: installs, migrates, doctor 0 errors / 1 warning (SQLite WAL advice), grant and check OK |
| 6 | `composer analyse` | 1 error `trait.unused` (`InteractsWithAzGuard`), the owner-WIP deletion of `KitCase`; not a defect |
| 6 | `pint --test packages tests bin`, `composer refactor:check`, `composer api:check` | passed |

The mysql and mariadb lanes ran one at a time. A foreign `pest --parallel` of another project (chatom) was running on the
machine during the mysql lane, which may account for its 23 min against 14 min for mariadb.
