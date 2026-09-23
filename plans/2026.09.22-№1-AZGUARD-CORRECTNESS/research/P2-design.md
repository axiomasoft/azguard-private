# Дизайн P2 — atomic mutations и DB revision fence

**Статус:** нормативный dossier для P2.1–P2.3.

## 0. Инварианты

1. Desired state полностью валидируется до первой destructive write.
2. Authorization rows и global revision меняются на одной connection в одной transaction.
3. Committed revision читается до cache hit; cache не является источником истины.
4. No-op не меняет rows/revision; retry не создаёт второй semantic transition.
5. Невидимые UI rows не удаляются managed sync.
6. Cache failure не превращает прежний warmed allow в success.
7. Reset не вызывает configured store `flush()`.

## 1. Current mutation map

```text
Filament RolePermissionsRelationManager -> delete all -> bulk insert
CLI RolePermissionsCommand              -> own mutation path
HasRoles / HasScopedRoles               -> attach/detach/sync + listeners
GrantBuilder / ContextGrantBuilder      -> model mutations + inline flush/events
PermissionCache                         -> local/durable epoch + locks
CacheResetCommand                       -> store-wide flush
```

F4–F8 show three independent failures: destructive/non-validated sync, incomplete holder
enumeration/invalidation, and commit/cache failure windows.

## 2. Atomic managed sync (P2.1)

Input is a normalized selection:

```text
role identity
selected tuples(panel,key)
managed universe tuples(panel,key)
expected fingerprint of current managed subset
operation scope (Filament rendered set | CLI whole-panel | one-key add/remove)
```

Algorithm:

1. normalize/dedupe tuples;
2. validate all selected keys and selection ⊆ managed universe where applicable;
3. open role-model connection transaction and lock role row;
4. re-read current rows, derive current managed subset, compare stable fingerprint;
5. on mismatch throw retryable conflict before writes;
6. compute add/remove diff and preserve every row outside managed universe;
7. apply diff through configured model semantics;
8. return immutable changed/no-op result.

Fingerprint is sorted canonical tuples, not row IDs/timestamps/order. Filament managed universe
is exactly what its form rendered; wildcard/dynamic concrete/unknown-panel rows survive unless
explicitly managed. CLI `sync --panel` owns a whole panel; add/remove own one key.

## 3. Global permission-state revision (P2.2)

One seeded DB row contains monotonic revision. It is stored with supported authorization data on
the same effective connection. Each official mutation either joins or owns one connection-aware
transaction and bumps revision if and only if semantic rows changed.

Read protocol:

```text
if authorization connection transactionLevel > 0:
    bypass request/durable/scoped caches (reads and writes)
    resolve fresh on that connection, ignoring loaded relations
    return this-check-only result; never publish it after nested/outer completion
else:
    R = read committed revision from DB
    cache key = subject/panel/discriminator + deployment generation + R
    hit with R -> eligible for P1 expiry validation
    miss -> resolve authoritative data
    before store/return ensure state still belongs to R where the chosen protocol requires it
commit mutation -> rows and R+1 become visible together
```

A refill begun under R may populate only an R key. It cannot poison R+1. An outer rollback keeps
both rows and revision at R. Revision write failure rolls mutation back. The scalar is deliberately
global: correctness without enumerating polymorphic/scoped holders is worth broader cache misses.

The transaction-level branch applies even to read-only or externally opened transactions and
prewarmed local entries. A same-connection check otherwise sees its own uncommitted R+1 and may
store an allow that a later unrelated commit reuses after rollback. A second revision read inside
that transaction cannot prevent the collision. If the connection/transaction state is uncertain,
fail closed. After the outermost commit or rollback, reread committed revision before cache use.

Loaded Eloquent `roles` and `ScopedRoleCache` are revision-aware or explicitly refreshed on
recompute; otherwise the resolver can refill the new key from stale in-memory relations.

## 4. Mutation ownership

- P2.1 synchronizer bumps revision explicitly inside its transaction.
- Official role/scoped/direct/context builders and traits use one coordinator/helper.
- Current public events remain notifications. Listeners/observers may be safety nets, never a
  second authority that double-bumps or defines correctness.
- Arbitrary consumer bulk SQL is outside official path and requires documented maintenance reset.
- Split authorization/revision connections fail before writes; no distributed commit promise.

## 5. Cache failure and reset (P2.3)

Outside a transaction read revision before consulting local or durable cache. Inside any
authorization-connection transaction bypass every reusable tier without cache I/O. Catch
transport operations narrowly:

```text
cache get/put/lock failure -> drop matching local entry -> uncached authoritative resolve
source/DB failure          -> propagate/fail closed; never relabel as cache miss
warmed local R != current R/generation -> unusable
```

Deployment generation is an explicit scalar included in key material. Reset transactionally bumps
DB revision and clears local request state. It reports the new value and never enumerates/deletes
foreign cache keys. Finite TTL lets old namespaces expire.

## 6. Concurrency scenarios

1. Two editors read fingerprint A. First commits B; second locks, sees B != A, returns conflict and
   performs no delete.
2. Reader starts resolution at R. Mutation commits rows+R+1. Reader may store under R only; next
   check reads R+1 and cannot see old set.
3. Inner transaction changes rights, outer transaction rolls back: revision and rows remain R.
4. Cache lock times out after a committed change: resolver recomputes from DB or errors; it never
   returns warmed R value after observing R+1.
5. Reset shares cache store with app sentinel key: sentinel remains byte-identical.
6. Committed R=7, grant A changes rows and R to uncommitted 8 on one connection; an inline check
   returns its own current result without cache I/O. Outer rollback restores 7. A different
   mutation B commits R=8; another reader sharing the store cannot hit an allow for rolled-back A.

Pseudocode and interleaving tables: `../artifacts/P2-design/examples.md`.

## 7. Failure modes

- Delete-all then insert; validation via model hooks after destructive write.
- `Role::users()` enumeration as invalidation truth.
- Cache epoch bumped before commit; afterCommit callback treated as durable.
- Swallowing source/DB exceptions in broad cache catch.
- Store-wide flush or prefix enumeration; process-local array fallback used as distributed lock.
- Bumping on no-op merely to satisfy a test count.

## 8. Validation matrix

P2.1: invalid selection, injected insert failure, duplicate/no-op, stale editor, invisible rows,
CLI scope. P2.2: before/after commit interleaving, same-connection uncommitted 8 → rollback →
unrelated committed 8 cache collision, nested commit/rollback, prewarmed local/scoped bypass, revision-write failure,
multi-morph/scoped holders, loaded relation and scoped cache. P2.3: get/put/lock faults, source
fault distinction, generation change, safe reset with foreign key, recovery. Deterministic faults
run without Redis; real isolated Redis supplies cross-process evidence when available.

## 9. Item mapping

| Item | Owns |
|:--|:--|
| P2.1 | synchronizer, selection/result/conflict, Filament/CLI callers |
| P2.2 | migration/revision/coordinator and official mutation integration |
| P2.3 | transport-failure policy, deployment generation, reset and final seam review |
