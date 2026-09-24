# Дизайн P7 — qualification matrix and pre-tag release contract

**Статус:** нормативный dossier for P7.1–P7.2.

## 0. Invariants

1. P7 aggregates terminal evidence; it does not silently repair P1–P6 product defects.
2. Declared support requires Composer-resolved installability and executed relevant lanes.
3. Skip/unavailable is not GREEN, especially for Redis and real SQL engines.
4. Every DB target is proven isolated `*_test`; Redis uses unique prefix and scoped cleanup.
5. Existing measured thresholds remain unless a separate accepted evidence changes them.
6. Changelog is in candidate commit before tag; release workflow is read-only after tag.
7. P7 performs no tag, push, GitHub Release, split or Packagist publication.

## 1. Qualification evidence model (P7.1)

Start from P1–P6 terminal states and map each acceptance behavior to its owning focused test.
Add only a missing integration assertion; if it exposes product failure, reopen the owning phase
item rather than patching semantics in P7.

Result table per lane:

```text
command | PHP/Laravel/Testbench/Filament | backend | target isolation | pass/fail/skip | evidence
```

Composer grid is derived from root/package constraints and existing CI includes/excludes. Clean
resolution exercises minimum/stable dependency modes for each declared cell. An infeasible cell
records exact solver conflict and aligns declaration and CI together; matrix rows are not added for
appearance.

SQLite/array remains fast behavior lane. PostgreSQL 16/MySQL 8 execute P6 migrations on isolated
test DB. Redis 7 lane provisions service and PHP extension, unique prefix, two-process race and
post-commit/revoke/failure scenarios. Missing service/extension or relevant skipped test fails lane.

API snapshot delta needs explicit additive/breaking/version verdict. PHPStan/Pint/Rector/types,
coverage and mutation gates report actual driver/version/outcome. Existing floors are not raised
without measured, separately accepted rationale.

## 2. Cross-phase scenario map

| Contract | Minimal integrated evidence |
|:--|:--|
| typed identity + expiry | two morph types, deadline boundary, request + real Redis process |
| revision fence | mutation commit/revoke observed by another process; rollback stays old revision |
| model override | configured subclasses across core + Filament + revision connection |
| panel lifecycle | request/job reset and foreign Gate policy fallback |
| role identity | two panel roles survive sync and assignments |
| migration identity | fresh and recorded upgrade on SQLite/PG/MySQL |

## 3. Documentation reconciliation (P7.2)

Docs describe only terminal behavior. Required old→new sections: v2 cache cold start and custom
source deadline limit; one-DB revision/cache failure; model subclass/split-connection boundary;
strict panel/default/attributes opt-in; qualified role names; fresh vs recorded migration and
MariaDB verdict. Definition/record/assignment glossary clarifies concepts without renaming code.

RU/EN pages are paired with relative links. Single root `CHANGELOG.md` under `[Unreleased]` records
release-visible changes; historical package ledgers are read-only.

## 4. Candidate release preflight

Human sequence:

1. choose version/BC based on actual API/default/schema delta;
2. promote reviewed root changelog section in candidate commit;
3. run read-only preflight against candidate ref;
4. owner separately approves tag/push;
5. tag workflow reads tagged tree, repeats preflight, then may publish.

`bin/release-preflight.sh VERSION REF` uses `git show REF:CHANGELOG.md` (or equivalent plumbing),
requires exact version heading and never trusts working tree/network. Remove tag-triggered workflow
that generates and commits changelog after tag. Preserve existing split guard.

## 5. Worked cases

- Changelog edited only in working tree while candidate ref lacks version: preflight fails.
- Tag points to commit with matching version section: preflight passes without modifying checkout.
- Redis test skipped because extension missing: dedicated lane fails, while SQLite lane may remain
  separately green.
- Composer lowest cell fails on exact Testbench/Laravel conflict: record solver output and correct
  support declaration plus CI matrix in one change.

Examples: `../artifacts/P7-design/examples.md`.

## 6. Failure modes

- Full suite called GREEN with skipped backend carrier.
- Running tests against unresolved dev/prod DB; cleaning whole Redis database.
- Auto-updating API snapshot without BC decision.
- Duplicating all predecessor tests or fixing their product code in P7.
- Creating changelog commit after tag; reading working-tree changelog in release job.
- Publishing/tagging under plan execution authority.

## 7. Validation and mapping

P7.1 validates composer grid, affected package/SQL/Redis/cross-package tests, API/static/refactor/type
and measured quality gates, then one matrix seam review. P7.2 validates RU/EN/docs build, upgrade
mapping, git-tree preflight fixtures and release workflow order, then one docs/release review.

| Item | Owns |
|:--|:--|
| P7.1 | support/qualification matrix, real Redis and narrow integration gaps |
| P7.2 | docs/upgrades/changelog/preflight/workflow recipe |

