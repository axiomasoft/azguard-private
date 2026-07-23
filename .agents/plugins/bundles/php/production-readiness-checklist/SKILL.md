---
name: production-readiness-checklist
bucket: php
version: 0.1.0
description: "Verify production-readiness invariants happy-path tests miss: transaction atomicity, race-condition-safe idempotency, config defaults, non-nullable cursor keys, after-commit side-effects, soft-delete, id-strategy compatibility, single queue-retry owner, atomic state transitions. Activate before/at review of risky Laravel slices (services, queues, drivers, migrations), queue semantics, or before merge."
risk: read
persona: oss-dev
tags: ["php", "laravel", "review", "production", "reliability", "quality"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Production-Readiness Checklist

A whole class of defects is invisible to per-unit happy-path tests (they force the needed state). Verify these on risky slices BEFORE merge — by reasoning, not just by green tests.

## Atomicity & side-effects

- Multi-table mutations inside `DB::transaction` (a rollback leaves no half-write)?
- Events/broadcasts/jobs fire AFTER commit (`dispatchAfterCommit` / after-commit listener), not inside the transaction?

## Race safety / idempotency

- Concurrent retries can't double-process: dedup via unique / `INSERT … ON CONFLICT`, or `lockForUpdate` on read-before-write — NOT plain read-then-insert.
- Exactly ONE owner of retry (queue OR a relay sweep, not both — a double owner races on `attempts`).
- State transitions are atomic: check+update under transaction/lock, with a validated source state.

## Config honesty

- Default works out-of-the-box, OR is explicitly `null` + `throw` on use. Anti-pattern: `?? 'echo'` with no registered driver (a test forcing `'null'` hides this).

## Data shape

- Cursor/seek pagination keys on a NON-nullable sortable column (NULL isn't comparable → unstable cursor; a test stamping a timestamp hides it).
- Soft-delete where data loss / cascade matters (not hard `delete()`).
- Behavior holds across ALL id strategies in use (uuid7 / ulid / bigint) — no uuid-string written into a bigint column.

## Queue semantics

- `ShouldQueue` tests that call `handle()` directly DO NOT cover `release()/backoff()/visibility` or concurrent workers — flag "queue semantics not covered".

## Test honesty

- Any test workaround / forced config is a `[POSSIBLE-DEFECT]` flag (>60% hit rate on a real bug) — investigate the root cause, don't mask it.

## Related

- `php:transactional-outbox-relay`, `laravel-architecture:enum-state-machine`, `php:cursor-pagination-design`, `quality:code-review`.

<!-- ru-source-sha256: b8c97c17c5a6dfeae87fa74d18442d1fecdf6d32d196eec5c8be3f049b265881 -->
