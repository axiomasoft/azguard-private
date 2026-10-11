Verdict: GREEN

## Findings

None.

## Commands and observations

Candidate is `e4da45ff82d8f559dd26eac9263ca00e6e82ec89` plus the uncommitted P2.1 tree. Round 1 inspected `ef2ff4bf`. Commits since then are plan bookkeeping only: `236e5acc` (P2 design) and `e4da45ff` (round-1 review artifacts).

F1 is corrected. `docs/upgrade.md` opens with `# Upgrading` and then `## From 0.7 to 1.0 (instructions for AI agents)` at line 3. The next heading is `## From 0.3 to 0.7.0` at line 15. The new section has two `Find:` → `Replace:` bullets:

- `@api` / `@spi` docblock tags are replaced by `#[AzGuard\Attributes\Api]` and `#[AzGuard\Attributes\Spi(kind: SpiKind::Implement|Call)]`. `packages/core/src/Attributes/Api.php`, `Spi.php`, and `SpiKind.php` exist. `SpiKind` has `Implement = 'implement'` and `Call = 'call'`. `grep -R -E '@api\b|@spi\b' packages --include='*.php'` finds no tags.
- Manifest readers replace `"stability"` and `"via"` with `"publication"` (`api`, `spi-implement`, `spi-call`) and `"since"`. On `HEAD`, `packages/core/api-manifest.json` has class keys `stability` (247) and `via` (248) and no `publication`. In the working tree those class keys are gone; `publication` is 230, values are `api` / `spi-implement` / `spi-call`, `since` is present, `enum` is `closed` or `open`, and `contracts`, `namedArguments`, `roots`, and core `facade` (empty list) are present. One remaining `"via"` is a parameter name (`classes[211].methods[3].parameters[1].name`), not the old class key.

The five deleted bullets are absent. `docs/upgrade.md` does not name `ModelSubjectDirectory`, `ModelTenantDirectory`, `QueryScopeDirectory`, `PatternMatcher`, or `StorageDescriptor`. Exact `Find:` lines for those five symbols match nothing in the tree. `packages/core/src/Storage/StorageDescriptor.php` is still absent. `PermissionPattern::covers()` remains only in the round-1 review record.

`grep -q '^## From 0.7 to 1.0 (instructions for AI agents)' docs/upgrade.md` exits 0. `bash bin/docs-language-gate.sh` prints `[docs-language] OK — documentation is English-only.` and exits 0.

Nothing else in the product tree changed after round 1. Round 1 recorded `307 files changed, 15011 insertions(+), 11232 deletions(-)` against `ef2ff4bf`. `git diff HEAD --shortstat` is now `297 files changed, 14888 insertions(+), 11213 deletions(-)`. The drop is the ten tracked plan files committed in `ef2ff4bf..HEAD` and now clean (`brief.json`, six knowledge JSON files, `phases/P2/phase.json`, `phases/P3/phase.json`, `phases/P4/phase.json`: +107/−19), the five deleted upgrade lines (−5 insertions), and `log.jsonl` (the `17:23:42Z` review note is in `e4da45ff`; the working tree adds one `17:24:49Z` deviation). 15011 − 107 − 12 − 5 + 1 = 14888 and 11232 − 19 = 11213. The only dirty path outside `packages/`, `tests/`, `bin/`, `docs/`, and `CONTRIBUTING.md` is `plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/log.jsonl`. Files newer than the round-1 review file are `docs/upgrade.md` (21:23:24), the committed receipt, `log.jsonl`, and gitignored `views/handoff.md`. Untracked product files are still `Attributes/Api.php`, `Spi.php`, `SpiKind.php`, `tests/Arch/ApiUsage.php`, `tests/Arch/SpiPositionalCallsTest.php`, and `tests/Arch/Allowlists/i4.json`.

## Material limits

Round-1 product coverage (manifest `--check`, Pest, PHPStan, Composer, Docker, database) was not re-run. The retained round-1 stream truncates `git diff --stat` in the middle, so sameness of the other dirty product files is the stat identity above plus mtimes, not a saved per-file blob. `views/` is gitignored.

Model: Grok 4.7. A separate effort setting is not exposed in this session.
