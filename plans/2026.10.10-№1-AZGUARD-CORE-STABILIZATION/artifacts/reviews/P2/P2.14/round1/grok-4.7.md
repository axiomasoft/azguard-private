Verdict: GREEN

| id | location | violated clause | severity | trigger / consequence / reproduction | minimal correction |
| --- | --- | --- | --- | --- | --- |
| — | — | — | — | none | — |

## Coverage

| Clause | Result | Evidence |
| --- | --- | --- |
| (a) Instruction vs upgrade-map / type-map / code | Complete for P2 supported changes, and the checked claims match the code | All 16 published P2 `rename`/`move`/`retire`/`split` rows (including `api(location)` `PluginDependencyMissingException`) occur once as `AzGuard\…` in `## From 0.7 to 1.0`. Upgrade-map P2 types, members, and config/CLI tokens are in that section (55 `Find:` / `→ Replace:` bullets, none malformed). `CurrentContext` / `WithinContext` live at `Context\Internal\` with no `#[Api]`/`#[Spi]`; reads are `PanelAccess::scope()` (`ScopedPanelAccess.php:82`), `AzGuard::currentScope()` (`AzGuard.php:186`), `AzGuard::withinScope()` (`AzGuard.php:170`). `TenantPolicy::mode(): TenantMode` (`None`/`Required`). `Panel::writer(): ?WriterSchema` with `storage`, `dynamicPermissions`, `rolesOnly` (`Panel.php:444`, `WriterSchema.php:16`). `Command::INVALID` is 2 via `CommandSupport::attempt()` (`CommandSupport.php:46-54`); `azguard:explain` and `azguard:storage:migration` throw `InvalidCommandInput` inside `attempt()`. `ConfigSections::own()` matches the bullet (`ConfigSections.php:37`); unknown roots fail `assertKnownKeys` (`AzGuardConfig.php:107`). `gate` keys are only `enabled` (`AzGuardConfig.php:26`). `WriterConflictException` is the database source attached twice (`PanelSources.php:135-138`). |
| (b) `MigrationGuideTest` | Non-vacuous and aligned with the type-map file | Parser column 6 is `action / phase`, column 7 is `was`. `api`/`spi` prefixes include `api(location)`. Section slice stops at the next `\n## ` (`docs/upgrade.md:90`). Each of the 16 required FQCNs appears once, so deleting that bullet fails `str_contains`. Pest: 3 tests passed inside the 34-test run below. |
| (c) Consumer fixture | Independent happy path through all five slots; invalid contributions already covered elsewhere | Namespace `Acme\Consumer`, loader `tests/Fixtures/Modules/Consumer/autoload.php`. `composer.json` autoload-dev is only `AzGuard\Tests\` → `tests/`. All 24 AzGuard imports are `api`, `spi-implement`, or `spi-call` in `packages/core/api-manifest.json`, and every called member is on that manifest. `ConsumerPlugin::register()` contributes before hook, restriction, pipe, participant, and evaluator. Contract traits run the engine: participant retry asserts `prefix + consumer_rows` row counts (`ChangeParticipantContractTests.php:170-173`); evaluator `allows`/`allowsMany` is compared directly (`AssignmentScopeEvaluatorContractTests.php:70-84`). Invalid slots are `tests/Contracts/NegativeFixturesTest.php` (restriction, hook, pipe, plugin, evaluator, participant) and `tests/Feature/Panels/SlotContractsTest.php` (closure mismatches), already listed on I8 and I14. |
| (d) `invariants.json` | Statuses still match the architecture table; new checks exist and test those invariants | This commit only extends `checks` for I6, I8, I11, I14. `InvariantRegisterTest` agreement test passed. I6: `WriterSchemaTest` rejects `apply()` outside the pipeline; `ChangeParticipantsTest` rejects undeclared and core tables. I8: the five consumer suites. I11: `ChangeParticipantsTest` asserts one audit row and the published `eventId`; `JournalParticipantContractTest` runs the journal participant through rollback, retry, and no-effect. I14: `ModuleOptionsTest` is two-panel options; `SlotContractsTest` rejects bad slot closures. |
| (e) Named allowlists and I4, I6, I7, I8, I11, I14, I20 | No P2-owned allowlist entry remains; I4/I7/I20 checks were already in place | `i2` 18×P3, `i3` empty, `i4` 11×P3, `i5` 3×P3, InactiveCost 1×P3 (`audit_log`), `i7` empty, `i9` `findings` `{}` and `disagreements.assignments` `[]`. Every i2/i4 symbol’s type-map phase is P3 (or `audit_log`, which the allowlist protocol keeps for P3). I4 is `ApiManifestTest` + `i4.json` (report mode, blocks after P3). I7 is `NoScopeDeclarationTest` over an empty allowlist. I20 is `SpiPositionalCallsTest` (“core calls Spi(Implement) methods positionally”). Statuses: I4/I7/I20 `build`+P2, I6/I8/I11/I14 `exists`+P2, matching “Build (P2)” vs “Exists + Build (P2)”. |

## Forward risks for P3

P3 still has to empty the owner-P3 allowlists: i2 (18, including `audit_log` and DevTools commands), i4 (11 internal types whose rows are `move`/`relocate` P3), i5 (`src/Audit`, `src/Plugins/Audit`, `src/Scopes`), and the InactiveCost `audit_log` row. The migration guard does not watch member, config, or CLI bullets, so a later edit can drop one of those lines without failing `MigrationGuideTest`. `NoScopeDeclarationTest` still requires any i7 entry to be owner P2; the list is empty, which is the P2 exit state. The four-driver matrix was not evidence on this commit.

## Commands run

- `git show --stat b6cbaf59` and the `docs/upgrade.md`, `MigrationGuideTest.php`, `invariants.json`, `zones.json` diff. HEAD is `b6cbaf59`. Worktree clean.
- `plan.py get` for P2.14, `knowledge/p2-execution` conventions and allowlists, `knowledge/upgrade-map`, `knowledge/architecture` invariants and release-gate, and `events PLAN1 --item P2.14`. Type-map parsed from the plan document and from `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/knowledge/type-map.json` (464 baseline rows; 16 required P2 rows).
- Allowlist owner scan and i9 file read, as in the coverage table.
- `php8.4 -d memory_limit=1G vendor/bin/pest tests/Arch/MigrationGuideTest.php tests/Arch/InvariantRegisterTest.php tests/Contracts/Consumer --fail-on-skipped` — exit 0, 34 passed, 457 assertions, 1596 ms. PHP 8.4.26.

## Material limits

Did not run `tests/Arch` as a directory, `composer` scripts, phpstan, rector, pint, docker, or any database driver or engine suite. Did not re-run the parity, inactive-cost, or baseline suites; i9 emptiness is from the JSON and `ParityLedger::findings()`. The non-vacuity of the migration guard is from the parser and the one-occurrence count, not from editing `docs/upgrade.md`.

## Model

Grok 4.7.