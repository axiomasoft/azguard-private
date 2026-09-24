---
name: testing-safety-report
bucket: quality
version: 0.1.1
description: "Use after a risky schema, layer, or dependency change to report checks and remaining risks."
risk: read
persona: architect
tags: [testing, safety, migrations, reporting, verification, quality, refactor]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
when_to_use: "Use when reporting validation and remaining risks after a risky change."
---

# Testing Safety Report

Post-fact summary making a risky change auditable. Complements `test-strategy` (test planning) and `refactoring-plan` (change plan).

## When to activate

After a change that may silently break behavior:
- DB migrations (new/changed fields, indexes, backfills, dropped columns);
- data schema / layers / contracts refactor;
- dependency / language / framework version upgrade;
- mass replace (rename, module move, internal API change).

Skip for small local edits with obvious coverage.

## Algorithm

1. **Structural changes.** List what changed in substance (not line-by-line diff): which entities/tables/contracts/layers affected, is migration reversible (`down()` correct?), is backward compatibility preserved.
2. **Checks run.** Which tests/analyzers ran and with what result: targeted tests of affected domains, full suite (if relevant), static analysis, linter. Give command and outcome (ok / N failed / not run).
3. **Risks & uncovered.** What is NOT covered by tests, which scenarios stayed manual, where regression is possible (data, concurrency, external integrations).
4. **Control checks.** Concrete follow-up: what to verify on staging, what data to migrate, what to monitor after deploy, rollback plan.

## Report format

```text
Safety Report: <short change name>
Изменено структурно:
  - ...
Прогнано: тесты (<команда> → ok/N упало), phpstan (ok/N), линтер (ok)
Риски / не покрыто:
  - ...
Контроль / follow-up:
  - ...
Откат: <revert | миграция down | feature flag | нечего>
```

## Quality checklist

- [ ] Structural changes described in substance, not line-by-line diff.
- [ ] Concrete checks run listed with commands and result.
- [ ] Uncovered parts and potential regressions explicitly named.
- [ ] Follow-up and rollback plan present.
- [ ] Report short and auditable, no fluff.

## Links

- `test-strategy` (quality) — test planning before the change.
- `refactoring-plan` (quality) — plan of the change itself and its steps (this report records its execution).
- `php/php-upgrade-checklist` — PHP upgrade checklist; report records the run result.
- `php/static-analysis` — phpstan/linter whose outcomes go into the "Прогнано" line.
- `laravel-testing/test-isolation-guard`, `devops/db-test-preflight` — safe test DB guarantees during the run.
- `oss-dev/dependency-audit` — dependency upgrade as a trigger for the report.
- `quality/code-review` — report attached to PR so reviewer sees safety boundaries.
