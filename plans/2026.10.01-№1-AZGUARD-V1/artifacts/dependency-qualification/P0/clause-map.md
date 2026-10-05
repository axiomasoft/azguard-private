# P0.2–P0.5 normalization for fresh qualification

Adapters must run from the clean isolated candidate checkout, using its rebuilt
vendor/autoload.php. Root owns per-check logs, candidate/context binding, new
receipts and observations. These files never rewrite historical evidence.
No qualification commands have been executed during adapter preparation.

The four per-item JSON files are actual-check definitions for root's qualification
runner. They are not a facade --checks bypass: frozen historical prose does not
currently parse through plan-work.validate. IDs are normalized check IDs, not
fabricated historical E receipts. Root must bind real observations to the frozen
carrier when producing new validation receipts.

## Shared checks: execute at most once

`checks-shared.json` provides suite, Pint, PHPStan (1G), Rector dry-run, type
coverage (>=98), and diff. Run after the lock-update adapter has restored the
original composer.lock. Root may reuse its actual qualified shared evidence on
the SAME candidate and validation context instead of executing these again.
For P0.5 clause 3 the shared suite check additionally logs JUnit and non-skipped
executed-test counts for Arch, Unit, Feature, Regression. If root reuses a suite
receipt, retain corresponding observed per-suite proof; file counts alone are
insufficient. JUnit is captured in the check log, temporary XML is removed.
Composer validates repeated in P0.4/P0.5 can likewise share identical actual
observations if root binds both clauses; per-item files retain standalone checks.

## Exact clause mapping

| Frozen item/carrier | Clause | Actual normalized checks |
|---|---|---|
| P0.2 E14 | 1 | P02.regression |
| P0.2 E14 | 2 | P02.negative-restored: P01a owning item changed to PLAN2.P9.9; real meta-test RED with that assertion; exact byte restoration in finally; real Regression GREEN |
| P0.2 E14 | 3 | shared suite, Pint, PHPStan, diff |
| P0.3 E10 | 1 | P03.fixture: actual archive installation and AzGuard provider discovery; exit 0 required |
| P0.3 E10 | 2 | P03.fixture-filament: actual archive installation and both providers; unavailable stays exit 3 / acceptance_green=false, never a GREEN or silent skip |
| P0.3 E10 | 3 | P03.shell-syntax; P03.shellcheck (actual executable absence printed as declared conditional not-applicable) |
| P0.3 E10 | 4 | P03.workflow-yaml: declared PyYAML parse; missing PyYAML fails, no fabricated result |
| P0.3 E10 | 5 | P03.clean-tree: observed full git status; any unignored tracked/untracked change fails |
| P0.4 E14 | 1 | P04.composer-root/core/filament: individual strict validates |
| P0.4 E14 | 2 | P04.lock-sync-restored: actual composer update --lock --no-install --no-scripts --no-interaction; exact third-party versions/membership compared; updated lock strictly validated; original lock restored in finally BEFORE remaining checks |
| P0.4 E14 | 3 | P04.release-preflight |
| P0.4 E14 | 4 | P04.old-names: declared git grep, all hits logged; only an explicit 0.3/legacy reference on README status line or in RELEASING passes; other hits fail |
| P0.4 E14 | 5 | shared suite, Pint, diff |
| P0.5 E14 | 1 | P05.composer-root/core/filament: normalize impossible multi-file Composer invocation into individual strict validates |
| P0.5 E14 | 2 | P05.dump-autoload; P05.show-core; P05.show-filament: two actual installed-package inspections instead of treating second package as a version argument |
| P0.5 E14 | 3 | shared.suite-with-counts, or SAME-candidate shared GREEN plus observed suite-count proof |
| P0.5 E14 | 4 | shared Pint |
| P0.5 E14 | 5 | shared PHPStan with --memory-limit=1G |
| P0.5 E14 | 6 | shared Rector dry-run |
| P0.5 E14 | 7 | shared type coverage >=98 |
| P0.5 E14 | 8 | P05.no-context: declared git grep semantics; exit 1 proves expected absence, exit 0 fails, >1 tool error fails |
| P0.5 E14 | 9 | P05.no-legacy-autoload: supplied real PHP assertion require vendor/autoload.php + class_exists('AzGuard\\Panels\\Panel') == false |
| P0.5 E14 | 10 | shared diff |

## Execution constraints

- Capture stdout/stderr and actual exit per check. Expected RED inside the
  corruption control is logged separately from restored GREEN; adapter success
  requires both observations.
- Check P03.clean-tree before root writes new unignored evidence into the isolated
  checkout. Capture logs outside that checkout, or in an already ignored output
  directory. Do not suppress product-tree mutations to make the status check pass.
- Each item list can run independently. Runner stop-after-RED must remain honest;
  a fixture unavailable is not an executed GREEN for subsequent checks.
- SIGTERM/SIGINT attempt restoration; a hard kill can leave a changed candidate,
  so root must check exact candidate/context freshness after execution.
- Current read-only inspection already found old-name hits outside P0.4's allowed
  historical locations (including .github/CONTRIBUTING.md). That check may produce
  a real RED; no location waiver is included.
