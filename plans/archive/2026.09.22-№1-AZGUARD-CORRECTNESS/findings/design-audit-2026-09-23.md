# Plan-wide design audit — 2026-09-23

**Verdict: RED.** D4's pre-execution GREEN gate is not met. This is a static
review of the finished P1–P7 design against the adopted invariants and the
checked-out code; no implementation or database migration was run.

## Material findings

### A1 — Major — P2.2 can publish an uncommitted revision to shared cache

`phases/P2/P2.2.md` Implementation Rules and `research/P2-design.md` §3 require
each resolver lookup to read the revision on the authorization connection and
cache by that value. They cover another reader before commit and an outer
rollback, but do not define reads made **inside the mutating transaction**.

Concrete interleaving: committed revision is 7. An official grant of permission
A begins an outer transaction, changes rows and increments the row to 8. An
authorization check on that same connection sees its own uncommitted rows and
revision 8, then writes an allow for A to the durable cache under `r8`. The
outer transaction rolls back, restoring committed revision 7. A later
independent mutation of permission B commits revision 8. Its readers can now
hit the entry produced by the rolled-back transaction and allow A, although A
is absent from committed rows. This violates `plan.md` invariant 3 and D5's
committed-state fence.
Re-reading revision before storing does not help while both reads share the
uncommitted connection snapshot.

Owning continuation: revise P2.2/D5 and its dossier with an explicit
transaction-local read/cache policy. A check at transaction level >0 must not
publish an uncommitted revision or permissions to request/durable caches that
can outlive rollback; define behavior for nested commit/rollback and test the
`7 -> uncommitted 8 -> rollback to 7 -> different commit to 8` sequence with
the same cache store and a second reader. Recheck P2.3's cache-failure rules
against that policy.

### A2 — Major — P6.2's exact MySQL index does not fit the default schema

`phases/P6/P6.2.md` and D9 require a full, exact, null-safe composite key for
MySQL 8, forbid prefixes/hashes, and promise fresh/upgrade parity on MySQL.
Current `MorphColumns::add()` uses Laravel's default `string` length for both
`model_type` and `scope_entity_type`; migration 000003 adds `panel_id` with the
same default. The installed Laravel `Builder::$defaultStringLength` is 255,
and the MySQL test connection uses `utf8mb4`. These three full string columns
alone can require `3 * 255 * 4 = 3060` index bytes. The indexed numeric ID/role
columns add at least 24 bytes, exceeding InnoDB's 3072-byte limit before the
null markers. The current 000005 truncates two type columns to 191 precisely
to fit. Therefore the specified exact replacement cannot pass the declared
default MySQL lane as written. The plan's "fail before replacing legacy index"
clause preserves data but leaves the required MySQL migration unshippable.

Owning continuation: revise P6.2/D9 with a feasible exact database invariant
for default and deployed schemas, including an upgrade path for values already
beyond any proposed bounded width. Calculate worst-case indexed bytes for each
declared morph type/charset and validate the emitted DDL on MySQL before
accepting the cross-engine claim. If support must narrow, make that an explicit
compatibility decision rather than a surprise migration failure.

## Bounded finding

### A3 — Minor — root context still describes completed phases as skeletons

`plan.md` lines 30–32 and 70–71 say all phases are skeletons and that sol will
fill Files/Required Reads/Validation. P1–P7 now contain detailed items, and
`handoff.md` says design finish is complete. The stale root prose can misroute
a fresh executor toward another design pass. Update it through the owning
design continuation when repairing A1/A2; keep the historical authoring fact
as dated history if needed.

## Coverage and checks

- Read the plan, D4–D12, phase/item contracts, normative P1/P2/P6 dossiers,
  execution sheet, handoff, verification findings, and relevant current code.
- `plan-lint.py`: 0 errors, 0 warnings. Generated state/status/decision checks
  pass. The stale P1.2 bundle was regenerated through `plan-views.py`; P1.1
  and P1.2 bundle checks now pass. An `--all bundle --check` still reports a
  missing P2.1 bundle because only the P1 frontier bundles are materialized;
  it is not counted as a product finding or a GREEN full-bundle check.
- The web adapter failed with a connection error. Direct access to the
  [official MySQL InnoDB limits page](https://dev.mysql.com/doc/refman/8.0/en/innodb-limits.html)
  confirmed its documented 3072-byte index-key limit. The P6 finding is a
  calculation from schema declarations, not a reproduced MySQL DDL failure.
- Task `plan_delivery._handoff()` rejects the material A1 owning continuation
  `design-item P2.2` with `PLAN_DESIGN_NOT_NEEDED`, because P2 has detailed
  unfinished items. This conflicts with D4's explicit repair-after-RED path.
  No external Task source was changed in this AzGuard audit. The handoff
  retains the repair commands as recommendations and guards the next audit
  behind completion of A1/A2; this is a delivery-tool limitation, not GREEN
  design evidence.
- No P1–P7 implementation tests, SQL engines, Redis, or release actions were
  run as part of this design audit.

Recheck A1 and A2 after their owning design repairs. Do not open execution
until the plan-wide design verdict is GREEN.
