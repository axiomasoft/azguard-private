---
id: D13
date: 2026-09-23
status: accepted
item: P2.2
items: [P2, P2.1, P2.2, P2.3, P7.1]
supersedes: [D5]
superseded_by: null
---
# D13 — Transaction-local authorization never populates shared caches

**Actor:** plan-design repair / Codex root
**Evidence:** `findings/design-audit-2026-09-23.md` A1; current resolver/cache and Laravel transaction API read in the P2 dossier. D5 remains historical; its managed sync, global revision and bounded review decisions continue except where this record tightens the read protocol.

## Решение

Official mutations change authorization rows and the global permission-state revision on the same DB connection in one transaction. Outside a transaction the resolver reads the committed revision before each request/durable/scoped cache lookup and keys reusable entries by that revision and deployment generation. A cache transport failure never converts stale data into an allow.

At **any positive transaction level on the authorization connection**, authorization checks bypass all reusable request, durable and scoped-role cache reads **and writes**, including an entry warmed before the transaction. They resolve from current DB state on that connection without reusing loaded Eloquent relations. The result is usable for that check only; neither nested commit nor rollback publishes it. After the outer transaction ends, the next check rereads committed revision and may use or refill caches. This policy covers read-only and externally opened transactions too; a per-connection transaction-level check, not a global request flag, selects the path. If the active authorization connection or transaction state cannot be established reliably, fail closed rather than use a cache. Split mutation/revision connections remain unsupported and fail before writes.

The role-permission synchronizer still uses a locked managed set and expected fingerprint; invisible keys survive and stale editors conflict before writes. No-op leaves rows/revision unchanged. Official role/scoped/direct/context paths use one revision authority; public events remain notifications. `guard:cache-reset` advances only AzGuard revision and clears local state, never flushes the configured store. External bulk writes require explicit maintenance revision bump/reset. P2's sole `light` seam review remains P2.3; D4's plan-wide design gate is separate.

## Почему

A same-connection check can see its own uncommitted revision and permission rows. Caching that result under a future revision permits a later, unrelated commit to reuse the same number after rollback. A second revision read inside the transaction has the same flaw. Bypassing every reusable tier is simpler and safer than trying to publish on commit or track savepoint outcomes.

## Consequences

P2.2 owns transaction detection, fresh reads and the rollback/reuse regression; P2.3 applies the same bypass to transport failure handling; P7.1 qualifies it in the cross-process lane. Checks inside a transaction cost DB work by design. One regression must exercise committed 7 → uncommitted 8 → rollback to 7 → different commit to 8 with a shared cache and a second reader, including nested transactions and a warmed request/scoped entry.
