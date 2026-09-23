# Дизайн P1 — typed cache identity и абсолютное истечение

**Статус:** нормативный implementation dossier для P1.1–P1.2. Это источник полной
связности, алгоритмов и примеров; item specs остаются компактными execution contracts.

**Входы:** F1–F3, D2, D3, `P1-design-rag.md`, текущие resolver/cache/source/context
paths и reproduction из `findings/verification.md`.

## 0. Инварианты

1. Authorization cache identity совпадает с persistence identity субъекта:
   `(persisted morph type, canonical string id)`; panel и discriminator — отдельные axes.
2. Никакая raw-конкатенация пользовательских сегментов не становится cache key.
3. Один encoder используется request cache, durable cache, epoch/invalidation и
   `ScopedRoleCache`; локальная оптимизация не имеет отдельной семантики.
4. `now >= validUntil` означает miss/recompute, никогда allow из кэша.
5. Deadline переживает merge/filter/wildcard/context strategy. Потеря metadata — defect.
6. P1 не обещает атомарный revoke: revision/commit fence принадлежит P2.
7. Публичные `GrantSource`/`PermissionLayer` return types сохраняются; расширение additive.

## 1. Current code and dependency map

```text
Authenticatable
  -> EffectivePermissionResolver::forUser()
     -> PermissionCache request key / durable key / epoch
     -> GrantSource chain
        -> DirectGrantSource (SQL expiry filter)
        -> DatabaseRoleGrantSource
     -> PermissionLayer chain
        -> ContextPermissionLayer (SQL expiry filter)
     -> PermissionSet merge/filter/wildcard

DirectGrant / ContextRole model changes
  -> current observers/listeners
  -> PermissionCache forget/epoch

HasScopedRoles
  -> ScopedRoleCache request-local key
```

Observed gap F1: resolver/observers pass only auth ID, so `User#1` and `Admin#1`
share the same request/durable identity. Observed gap F2: expiry is filtered only on
the SQL read; once a `PermissionSet` is cached, neither request hit nor durable hit knows
the grant deadline.

## 2. `SubjectIdentity` contract

Internal immutable value carries two semantic fields:

```text
type = persisted morph alias/FQCN returned by Eloquent morph contract
id   = exact string cast of persisted key
```

Construction has two entry points: live `Authenticatable`, and persisted original/current
tuple for observers. It must not rediscover an old identity from mutated attributes.
`1` integer and `"1"` string normalize equally; `"01"`, UUID case rules and arbitrary
string IDs are not rewritten beyond the package's actual persistence contract.

Canonical bytes use a length-delimited/structured encoding, for example JSON array with
fixed field order and throwing serialization. Physical key material is a deterministic
digest with a versioned prefix. The complete produced key, not only the digest, must fit
the PSR-16 64-character portability limit.

Dimensions:

```text
subject digest = H(schema, morph_type, canonical_id)
epoch key      = azg:v2:epoch:H(subject, panel)
permission key = azg:v2:perm:H(subject, panel, discriminator, epoch/revision)
scoped key     = H(subject, entity type/id, panel/context dimensions)
```

Panel/context strings are data inside canonical material, not raw key segments. P1 does
not add tenant/connection identity without an accepted persistence contract.

## 3. Invalidation algorithm

Create/update/delete of DirectGrant or ContextRole determines every affected full identity.
On move/update, capture both original and current `(type,id)` before values are lost, dedupe
equal tuples, and invalidate each. Subject+panel epoch invalidates all discriminators/contexts
for that subject boundary. No store-wide flush is allowed.

V1 keys are never read after v2 rollout and expire naturally. This avoids an unreliable
enumeration/migration of arbitrary cache backends. Documentation must call the first deploy
a cold cache namespace change.

## 4. Deadline-aware `PermissionSet`

The current immutable set gains nullable absolute `validUntil`:

```text
merge(A, B): keys = union; validUntil = minimum(non-null deadlines)
filter(A, predicate): keys filtered; validUntil unchanged
empty derived from expiring source: deadline retained until resolver recompute decision
wildcard short-circuit: may short-circuit keys, never metadata
```

Built-in DirectGrant/context reads return active keys plus the nearest active `expires_at`
in one bounded query/read. They exclude `expires_at <= now`. Legacy custom sources that do
not supply a deadline remain TTL-limited; this compatibility gap is explicit, not hidden.

## 5. Cache envelope and hit state machine

Durable v2 envelope is strict and deployment-stable:

```json
{"version":2,"keys":["app.invoice.view"],"valid_until":"2026-09-23T12:00:00.000000Z"}
```

Reader order:

1. load payload;
2. require exact schema/version/types;
3. parse UTC instant with trusted clock;
4. when `now >= valid_until`, treat as miss and recompute once;
5. reject a recomputed already-expired result and do not cache it;
6. otherwise return the immutable set.

Raw v1 arrays, malformed timestamps, missing keys, wrong version and impossible envelopes
are misses, never best-effort allows. Request cache stores the same semantic object and runs
the same time check before every return. Backend TTL is `min(configured TTL, remaining
deadline)` with safe rounding; it is an eviction optimization, not the security boundary.

## 6. Worked scenarios

- `User#1/app` warms `invoice.view`; `Admin#1/app` must execute its own callback and may get
  empty. Forgetting User does not change Admin's key or result.
- Direct grant expires at `12:00:00`; a request cache warmed at `11:59:59` must miss at exact
  `12:00:00` without prune, listener or new process.
- Two contributors grant the same key, deadlines `12:00` and `13:00`: at `12:00` resolver
  recomputes and retains allow from the later contributor.
- A global wildcard with deadline cannot bypass deadline metadata by early return.
- Malformed v2 envelope is replaced from authoritative sources; it is never interpreted as
  an empty-but-valid or indefinitely valid set.

Concrete pseudocode lives in `../artifacts/P1-design/examples.md`.

## 7. Failure modes and forbidden shortcuts

- ID-only key; raw `type:id`; panel/context concatenation; backend-specific characters.
- Checking expiry only in SQL or only in durable tier.
- Relative seconds stored as authority; `sleep()` tests; stale-while-revalidate for auth.
- Per-key provenance graph without measured need; breaking v2 source/layer interfaces.
- Mass flush to roll v1; silently promising exact expiry for custom sources without metadata.

## 8. Validation matrix

P1.1 proves typed separation across request/durable/scoped keys, aliases/FQCN, int/string,
UUID/ULID and separator/unicode dimensions; original/current invalidation; key alphabet/length.
P1.2 proves frozen-clock before/at/after boundaries, fresh durable instance, multiple
contributors, merge/filter/wildcard/context strategies, strict envelope parsing and legacy
custom compatibility. Redis is additional cross-process evidence only when isolated and real;
absence is recorded as unavailable, not GREEN.

## 9. Item mapping

| Item | Owns | Consumes |
|:--|:--|:--|
| P1.1 | SubjectIdentity, v2 keys, typed invalidation, scoped key | F1, D3 §§identity |
| P1.2 | PermissionSet deadline, source propagation, strict envelope | P1.1 schema, F2–F3, D3 §§expiry |

## 10. Out of scope

Transactional revision, cache backend failure/reset, DB schema, per-subject revision vectors,
tenant/connection identity and custom-source mandatory migration remain outside P1.

