# Дизайн P6 — recovery-safe migrations and exact SQL identity

**Статус:** нормативный dossier для P6.1–P6.2.

## 0. Invariants

1. Fresh install and already-recorded upgrade are separate carriers.
2. Destructive dedupe never leaves an empty/partial pivot after failure.
3. Whole pivot is not materialized in PHP; maintenance window is explicit.
4. DB uniqueness encodes exact full tuple including NULL semantics.
5. Legitimate zero, empty string and all-zero UUID remain distinct from NULL.
6. Configured identifiers are validated segments and grammar-quoted, never interpolated SQL.
7. Unsupported engine/capability fails before legacy index removal.
8. Fresh/deployed MySQL paths fit the declared full-width key without truncating existing data.

## 1. Migration lifecycle (P6.1)

Base 000000 gains reverse FK-safe `down()`. Existing documented unsafe/null rollback limits of
other migrations are tested honestly, not relabeled.

For future fresh installs, 000005 is corrected. A database that already recorded 000005 cannot
observe that edit; P6.2 adds idempotent 000006. Upgrade docs distinguish both paths.
Future-fresh 000003 also bounds `model_has_scopes.panel_id` to 128 characters;
recorded databases receive a guarded narrowing only in 000006.

Role assignment dedupe:

1. create connection-local staged distinct exact assignment tuples in SQL;
2. verify source/staged cardinality invariants;
3. inside transaction replace source from stage;
4. on insert fault rollback original rows;
5. clean deterministic temporary carrier on retry.

Scoped assignment table has row IDs: stage `MIN(id)` keep-set grouped by full nullable identity,
then delete only non-kept IDs inside transaction, preserving timestamps of retained row. PHP may
process bounded metadata/IDs but never `distinct()->get()` the entire pivot.

DDL/index creation may have engine transaction limitations. Index failure is allowed to leave
complete deduped data and a failed migration that converges on retry; it may not leave partial data.

## 2. Exact null-safe key compiler (P6.2)

One internal helper owns create/drop/introspection and deterministic names. It receives validated
table/index identifiers, active connection/grammar and ordered identity components with types and
nullability.

Engine paths:

- PostgreSQL 16+: full composite `UNIQUE NULLS NOT DISTINCT`.
- SQLite: for each nullable component encode `(component IS NULL)` plus a **non-null full exact
  normalized value**, or use exhaustive unique partial indexes when that is clearer/proven.
- MySQL >=8.0.13, non-MariaDB: supported generated/functional parts with the same marker + full
  non-null normalized value; no prefix/substr/probabilistic hash. Declared support additionally
  requires InnoDB 16-KiB pages and DYNAMIC/COMPRESSED row format under current utf8mb4 schema.
- MariaDB: named unsupported verdict until a dedicated emitted DDL path passes isolated migration
  tests; do not infer support from `mysql` driver string.

For marker encoding, tuple semantics are:

```text
NULL       -> (1, normalized_fallback)
real value -> (0, exact_full_normalized_value)
```

The marker makes fallback domain collision impossible, but the fallback must be non-null so the
unique engine does not reintroduce NULL-distinct behavior. Every nullable component gets its own
pair. Normalization is type-preserving/canonical for int, UUID/ULID and string; it cannot truncate.

Before replacing old index, compile DDL, check engine/version/key limits and fail actionable if the
exact key cannot fit. Application preflight never substitutes for DB invariant.
The old three 255-character utf8mb4 strings alone can consume 3,060 bytes. Bounding panel to
128 leaves at most 2,552 bytes for those strings; the declared UUID worst-case budget including
IDs, role and null markers is approximately 2,852 bytes under 3,072. Calculate the actual
emitted functional/generated parts and execute DDL for int/ULID/UUID on isolated MySQL.
8-/4-KiB pages and unsupported row formats are explicit unsupported verdicts.

## 3. Forward migration and configurable names

000006 is idempotent: before any schema-changing DDL, inspect the actual engine/column and
reject existing non-null panel IDs longer than 128, with instructions for an explicit consumer
data migration; never truncate them. Confirm exact key capacity, then narrow the column,
invoke P6.1 dedupe, build/verify exact replacement before dropping legacy scopes index, add nullable unique
`roles.class_name` after P5 cleanup, and no-op when exact target state exists. 000005 uses the same
helper for fresh parity.

Identifier boundary accepts dot-separated segments only, rejects aliases/comments/expressions and
quotes each segment with active Laravel grammar. Context 000010 resolves one configured table for
both up/down. It does not silently move an already deployed default table after config change;
consumer performs explicit migration.

## 4. Worked cases

- `(scope_type='team', scope_id=NULL)` inserted twice must conflict.
- `(scope_id=NULL)`, `(0)`, `('')`, all-zero UUID remain separate legal identities where type allows.
- Two strings equal in first 191 chars but different later remain distinct; prefix index is failure.
- Fresh install and legacy 000005→000006 produce semantically identical target indexes.
- Injected insert failure during role dedupe restores exact original tuples; index failure leaves
  complete deduped data and rerun succeeds.

DDL examples: `../artifacts/P6-design/examples.md`.

## 5. Failure modes

- Editing historical migration presented as deployed upgrade.
- Delete-all + PHP-loaded distinct rows without transaction.
- Plain COALESCE sentinel without marker; marker plus still-null value; prefix uniqueness.
- Raw configured table interpolation; driver=`mysql` treated as MariaDB proof.
- Dropping old index before exact replacement capability/size is known.
- Claiming online/concurrent-writer safety.

## 6. Validation matrix

P6.1: fresh up/down/up, pre-000005 duplicates, failure injection, retry/cleanup, bounded memory and
maintenance lock evidence on SQLite/PG/MySQL test targets. P6.2: fresh vs upgrade DDL parity,
idempotent 000006, overlength-data and engine-capability fail-before-DDL, actual MySQL key-size/DDL
for int/ULID/UUID and 16-KiB vs smaller pages, tuple collision table, type variants, long values, quoted custom/schema names,
context up/down, explicit MariaDB verdict. DB test preflight must prove isolated `*_test` targets.

## 7. Item mapping

| Item | Owns |
|:--|:--|
| P6.1 | base rollback, staging/keep-set dedupe, failure/retry evidence |
| P6.2 | index compiler, 000006, fresh parity, identifiers/context, review/docs |
