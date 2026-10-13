You are a fresh independent read-only reviewer of PLAN1 items P3.4, P3.5, P3.6 and P3.7 (one batch) in the detached worktree you are started in (a checkout of commit c3e2c07a; vendor is linked). The owner requested reviews on Grok 4.7. Form your own conclusions.

IMPORTANT owner constraint: do NOT run long runs. No full test suite, no tests/Arch directory run, no composer scripts, no phpstan/rector/pint over the whole repo, no docker, no database driver or engine suites. Only read files and run at most a few specific test files (each under a minute), via php8.4 (system php 8.3 has a pre-existing SQLite defect), e.g. php8.4 -d memory_limit=1G vendor/bin/pest <one file>. Note: vendor is shared with the main checkout, so autoload of path packages may resolve to the main tree (same commit content); do not rely on it for differences.

Range under review: 304667bf..c3e2c07a (git log --oneline 304667bf..c3e2c07a). Per item: P3.4 = 3e389a77 (module PSR-4 roots, relocation of Scopes/Audit files; the commit also carries the shared files of P3.5–P3.7: core composer.json, AzGuardServiceProvider, AzGuardConfig, StorageSchema, Storage, StorageHealth, api-manifest, docs/upgrade.md, many tests), P3.5 = 2d87811a (storage split, Audit schema/migration, docs/advanced/operations.md), P3.6 = 7413da0b (plan json only; code is in the P3.4 commit), P3.7 = c3e2c07a (DevTools relocation). Read the product diff: git diff 304667bf c3e2c07a -- packages docs tests bin phpstan.neon rector.php phpunit.xml (ignore plans/ evidence). Plan API (from this worktree root): python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan.py --plans-root plans get PLAN1 P3.4 (also P3.5, P3.6, P3.7); 'get PLAN1 knowledge/p3-execution' (conventions), 'get PLAN1 knowledge/architecture --section invariants', and the decisions the items name (D19, D20, D21, D22, D11 etc.). Never read views/. tests/Arch/Fixtures/target-map.json is never edited by hand.

Check with particular care:
- DDL of audit_log and AuditSchema::differences (columns, indexes, per driver; byte equality with the previous DDL snapshots);
- the Audit migration: create-only, not auto-loaded by the provider, publish tag, basename identity;
- JournalPruner: row selection by cutoff, retry only on SQLSTATE 40001/40P01 / driver code 1213, no panel/authority lock held, batch/transaction behaviour;
- AuditPruneCommand uses only supported API (PanelRegistry, AuditOptions, Panel writer storage), no plugin-id literal, no core internals;
- DevToolsServiceProvider: in console only commands, no I/O at register/boot;
- ScaffoldConfig copy of the discovery folders defaults and refusal rule versus AzGuardConfig (drift risk);
- that core (packages/core/src) does not reference modules, the audit_log table, or the scaffold config section;
- migration lines in docs/upgrade.md (every moved/renamed FQCN, command, table, config, migration);
- removed and rewritten tests (allowed only by decisions D19/D20), and vacuous or weakened assertions.

Review for: (a) does the diff fulfil every clause of each item's scope/acceptance (verify in code, not in the item text); (b) behaviour regressions: semantics, ordering, error behaviour or public API/SPI surface (api-manifest) versus before; (c) violations of the p3-execution conventions and architecture invariants (no shims, placement, oracle rows, migration lines, tests); (d) vacuous or missing tests for the changed behaviour.

Report only verified actionable defects with a reproduction (command or file:line); a finding without one is at most plausible. Output Markdown starting exactly with 'Verdict: GREEN' or 'Verdict: RED', then a findings table | id | item | location | violated clause | severity | trigger / consequence / reproduction | minimal correction | (none if empty), a coverage table for (a)-(d) per item, commands run with observations, material limits, and your actual model if observable. Return the report and stop. Do not modify repository files or plan state.
