# P6 DDL and lifecycle examples

Exact emitted SQL is driver-tested; these examples state required semantics.

## PostgreSQL

```sql
CREATE UNIQUE INDEX model_has_scopes_identity_uq
ON model_has_scopes (model_type, model_id, scope_entity_type, scope_entity_id, role_id, panel_id)
NULLS NOT DISTINCT;
```

## Marker encoding

```text
encoded nullable X = (X IS NULL, COALESCE(full_exact_normalize(X), non_null_fallback))

NULL -> (1, fallback)
''   -> (0, '')
0    -> (0, '0')
```

The marker is part of the unique tuple; therefore real fallback values cannot collide with NULL.

## MySQL width and upgrade preflight

For the declared 16-KiB InnoDB/utf8mb4 profile, two 255-character morph types and
one 128-character panel consume at most 2,552 bytes before IDs, role ID and
null markers. The exact generated DDL must fit within 3,072 bytes for every
int/ULID/UUID variant; execute it on isolated MySQL, not only calculate it.
Before 000006 changes the schema, reject existing `panel_id` values longer
than 128 and unsupported page/row-format capacity, leaving rows and the old
index intact. After consumer data repair, a retry converges.

## Recovery state table

| Failure point | Required durable state | Retry |
|:--|:--|:--|
| before destructive DML | original rows | clean start |
| after delete/before insert | transaction rollback -> original rows | clean start |
| after committed dedupe/before index | complete deduped rows, no partial set | converges |
| after target index | complete rows + exact index | no-op |
