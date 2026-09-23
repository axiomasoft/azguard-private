---
id: D9
date: 2026-09-22
status: superseded
item: P6
items: [P6, P6.1, P6.2]
supersedes: []
superseded_by: D14
---
# D9 — Dual fresh/upgrade migration path и exact null-safe SQL identity

**Actor:** plan-designer/GPT-6 Codex root (provider selector `gpt-6-sol`; Task route mapping unavailable)
**Evidence:** RAG:— F18–F21, current migrations/tests and Laravel grammar source; RAG:✅ PostgreSQL/MySQL/SQLite/MariaDB primary docs recorded in `docs/reference.md`, found through one focused Perplexity query and opened directly.

## Решение

Fresh install and deployed upgrade are separate carriers. The package-owned 000000 receives
a reverse-order `down()`. 000005 is corrected for future fresh installs, but an installation
where it is already recorded is upgraded only by new idempotent 000006; docs state this
explicitly. Dedupe uses DB-side staging/keep sets and verifies cardinality before transactional
destructive DML. Insert failure restores original rows; index failure may leave complete deduped
data and a failed migration that safely converges on retry. Migrations require a maintenance
window; online/concurrent writers are not supported. No full pivot is loaded into PHP.

Null-safe scope identity is exact and driver-aware. PostgreSQL 16+ uses `NULLS NOT DISTINCT`.
SQLite and MySQL use a null marker plus normalized exact value for every nullable component;
therefore legitimate `0`, empty string and all-zero UUID do not collide with NULL. MySQL must
be non-MariaDB and at least 8.0.13; prefix-only uniqueness and raw identifier interpolation are
forbidden. If an exact key cannot fit engine limits, migration fails before replacing the old
index. MariaDB remains unsupported until its own isolated migration run proves a dedicated path.
Configured table/index names pass a strict dot-separated identifier boundary and active Laravel
grammar quoting. Context 000010 uses one configured table for create/drop; changing a deployed
table name remains an explicit consumer migration. P5's exact code-role identity is reinforced
with a nullable unique `roles.class_name` index after cleanup.

P6.1 has `Review=none`; P6.2 performs one final `light` review of all changed migration and
SQL-identity seams. No design-review/post-review/phase-audit chain is created. D4's one plan-wide
design audit remains the only later plan-level gate.

## Почему

Editing an already-recorded migration cannot upgrade a deployed database, while forward-only
repair cannot rescue a fresh install that fails earlier. The dual path covers both without
pretending history reruns. Ordinary unique indexes allow repeated NULLs on every target engine;
the current COALESCE sentinels and MySQL prefixes can merge distinct legal tuples. Engine-aware
exact keys keep the invariant in the database and make unsupported capability fail closed.

## Consequences

P6.1 owns rollback and recovery-safe dedupe. P6.2 owns the shared index compiler, new forward
migration, fresh parity, configurable identifiers, MariaDB verdict and the single light review.
Existing context tables are never renamed automatically; already-lost rows cannot be recovered.
