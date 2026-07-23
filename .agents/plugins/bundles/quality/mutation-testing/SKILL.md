---
name: mutation-testing
bucket: quality
version: 0.1.0
description: "PHP mutation testing via Infection: infection.json5 (source/excludes, mutators, logs, minMsi/minCoveredMsi gates), run with --threads and --git-diff-lines for CI increment, reading MSI and escaped mutants. Activate when setting up Infection, adding a mutation test-quality gate to CI, reading an MSI report, or investigating surviving mutants."
risk: write
persona: quality
tags: [quality, testing, mutation, infection, php, ci, coverage]
requires: [test-strategy]
produces_for: [code-review]
outputs: [infection.json5]
snippets: [infection.json5, mutation-ci.yml]
adapters: [claude, cursor, fable]
sha256: ""
---

# Skill: Mutation Testing (Infection)

Mutation testing measures test **quality**, not just coverage. Infection makes small changes (mutations) to source (`>`→`>=`, `+`→`-`, remove method call, invert condition), reruns tests. Tests fail → mutant killed. Tests still green → mutant escaped (line covered but no assert checks its behavior).

## Activate when
- Setting up Infection in PHP project (create/edit `infection.json5`).
- Adding mutation test-quality gate to CI (workflow or step).
- Reading Infection report — understand MSI / Covered MSI / escaped mutants.
- Investigating why mutants survive, where to add asserts.
- Choosing what to exclude from mutation (Commands, Facades, migrations).

Boundary vs `quality/test-strategy`: there = strategy (pyramid, which test level a feature needs, coverage policy). Here = Infection tool: config, run, gates, reading report. "How many/which tests" → `test-strategy`; "how to configure/read Infection" → this skill.

## Preconditions
1. **Coverage in XML format.** Infection requires line-coverage: coverage driver (Xdebug or PCOV) + run with `--coverage-xml`.
2. **Green test suite.** Otherwise all mutants are "already killed" by a broken suite.
3. **Infection installed:** `composer require --dev infection/infection`. Monorepo with multiple `src` — one root `infection.json5` with multiple `directories`.

## Algorithm
1. **Create `infection.json5`** at project root (see `snippets/infection.json5`). Key blocks:
   - `source.directories` — source dirs; monorepo/package: all `packages/*/src`.
   - `source.excludes` — what NOT to mutate (see "Exclusions").
   - `testFramework` — `pest` or `phpunit`.
   - `mutators` — `{"@default": true}` base; add `UnwrapArrayFilter`, `MethodCallRemoval` etc. pointwise.
   - `logs` — report outputs (`text`/`html`/`summary`/`json`).
   - `minMsi` / `minCoveredMsi` — gates.
2. **Run locally for baseline** — generate coverage, then run Infection against it:
   ```bash
   vendor/bin/pest --coverage-xml=build/coverage/xml --min=0
   vendor/bin/infection --threads=4 --coverage=build/coverage/xml --no-progress
   ```
   `--threads=4` (or `--threads=max`) parallelizes mutants — without it Infection is very slow. Reusing ready `--coverage` skips rerunning tests.
3. **Read report.** Main metric — **MSI (Mutation Score Indicator)**. Open `summary`/`html`, find escaped mutants.
4. **Lock gates.** Raise `minMsi`/`minCoveredMsi` in `infection.json5` to achieved level minus small buffer — prevent future degradation.
5. **Enable in CI.** Add step/workflow with incremental mode for PR and/or full run on merge to main (see `snippets/mutation-ci.yml`, "CI gate").
6. **Iterate over escaped mutants.** Each: either add an assert that kills the mutation, or deliberately ignore (`@infection-ignore-all` / disable mutator pointwise) if equivalent.

## infection.json5: key fields

| Field | Purpose |
|:---|:---|
| `source.directories` | Source dirs to mutate; packages — all `packages/*/src` |
| `source.excludes` | Globs/dir names excluded from mutation (Commands, Facades, migrations, Filament) |
| `testFramework` | `pest` or `phpunit` |
| `testFrameworkOptions` | Extra framework flags (e.g. `coverage-xml`, `junit`) |
| `mutators` | `@default` + pointwise on/off |
| `logs.text` / `.html` / `.summary` / `.json` | Report formats: `text` — escaped list, `html` — navigation, `summary` — aggregates, `json` — machine |
| `timeout` | Per-mutant timeout (sec); guards against infinite mutation loops |
| `threads` | Default parallelism (`--threads` overrides) |
| `minMsi` | Gate on overall MSI — fails CI if below |
| `minCoveredMsi` | Gate on MSI of covered lines only — fails CI if below |

**Mutator profile.** Start `{"@default": true}` (all "safe" mutators). Add specific (`UnwrapArrayFilter`, `MethodCallRemoval`) pointwise; disable noisy/equivalent (`"MutatorName": false`). Don't enable everything: extra mutators produce equivalent (unkillable) mutations and drop MSI uselessly.

## Gates: minMsi vs minCoveredMsi
- **MSI** = `(killed + timeout + error) / total_mutants`. Counts ALL mutants incl. uncovered code (auto "not killed"). Low coverage → low MSI.
- **Covered MSI** = same formula, denominator = mutants in **covered** lines only. "Of what tests touch, what fraction do they actually verify?"
- Both: `minMsi` catches overall gap (incl. uncovered), `minCoveredMsi` stricter on existing-test quality. Typical: `minMsi: 70`, `minCoveredMsi: 80` (Covered higher — expect better asserts on covered code).
- Gates duplicated: in `infection.json5` (local) and/or `--min-msi` / `--min-covered-msi` flags in CI. Flag overrides config.

## Interpretation: MSI and escaped mutants
- **Killed** — tests failed after mutation. Good: behavior verified.
- **Escaped** — tests passed despite mutation. Assert gap: line runs, result unchecked. Main review target.
- **Not Covered** — mutant in uncovered line; tests don't run it. Coverage question (`test-strategy`), not assert quality.
- **Timeout / Error** — mutation caused loop/fatal; counts as killed.
- **Equivalent mutant** — mutation doesn't change observable behavior (e.g. `<`→`<=` on a boundary unreachable by business logic). Unkillable; mark `@infection-ignore-all` or disable mutator pointwise. Don't chase 100% MSI — last percent often equivalent.

Escaped triage: open `logs.text` or HTML → find `escaped` → understand which change survived → add assert that fails on the mutation (check concrete value/branch, not "didn't crash"). Escaped ≈ weak assert (`assertTrue($x)` instead of `assertSame(3, $x)`).

## Exclusions: what not to mutate
Exclude code where mutations are noise not signal (`source.excludes`):
- **Commands / Console** — CLI entry points; logic belongs in (tested) services. Mutating command declaration is useless.
- **Facades** — thin static proxies; nothing to mutate.
- **Migrations / Database** — deterministic DDL, not covered by unit asserts.
- **Generated code / admin UI layer (Filament etc.)** — framework declarations, mutations equivalent.

Rule: exclude **entry points and declarative/generated code**, keep **domain logic** (services, policies, value objects) — where mutants find weak tests.

## CI test-quality gate
Mutation testing is expensive (full suite per mutant). CI strategy (`snippets/mutation-ci.yml`):
1. **Increment on PR** — mutate changed lines only: `--git-diff-lines --git-diff-base=origin/main`. Fast, holds the bar on new code.
2. **Full run on merge to main / scheduled / manual** — advisory, not blocker.
3. **Coverage in separate step** (`--coverage-xml`), reused by Infection via `--coverage=` — avoid running tests twice.
4. **Gate flags:** `--min-msi=70 --min-covered-msi=80 --no-progress`. On increment — same bar applies to diff.
5. **Coverage driver:** enable Xdebug/PCOV in CI (`coverage: xdebug`, `XDEBUG_MODE=coverage`) — else no line-coverage, Infection won't run.

Increment needs full git history (`fetch-depth: 0`) and available compare base (`origin/main`), else `--git-diff-lines` finds no diff.

## Quality checklist
- [ ] `infection.json5` at root: `source.directories` cover all `src`, `excludes` cut Commands/Facades/migrations/UI
- [ ] Mutator profile started from `@default`; extra/equivalent disabled pointwise, not "everything"
- [ ] `logs` set (min `text` + `summary`) — escaped mutants readable
- [ ] `minMsi` and `minCoveredMsi` locked (covered ≥ overall), not below achieved baseline
- [ ] Local run uses ready `--coverage`, with `--threads` (not single-threaded)
- [ ] CI: increment `--git-diff-lines` on PR; full run on main advisory, not blocker
- [ ] CI: coverage driver enabled and `fetch-depth: 0` for diff mode
- [ ] Escaped mutants triaged: precise asserts added or marked equivalent (`@infection-ignore-all`), not left silent

## References
- https://infection.github.io/guide/
- https://infection.github.io/guide/mutators.html
- https://infection.github.io/guide/command-line-options.html
- snippets/infection.json5 — config with gates, mutators, logs
- snippets/mutation-ci.yml — CI step: increment `--git-diff-lines` + full run
- Related skills:
  - `quality/test-strategy` — strategy and coverage policy (what to cover; MSI catches weak asserts in already-covered)
  - `quality/code-review` — MSI as objective test-quality gate at review
  - `php/static-analysis` — neighboring PHP-quality CI gate (Pint/PHPStan/Rector); mutations check tests, static analysis checks code
  - `laravel-testing/laravel-testing` — Pest suite and coverage driver (Xdebug/PCOV) Infection runs on
  - `php/pao` — compact PHP-tool output (incl. Infection) for in-agent work
  - `devops/ci-cd` — where the mutation workflow lives in the pipeline

<!-- ru-source-sha256: c02cdf0dcd6da1db9c549656b6ab0c37995cbd3f2b24870344dd371c660ea3a9 -->
