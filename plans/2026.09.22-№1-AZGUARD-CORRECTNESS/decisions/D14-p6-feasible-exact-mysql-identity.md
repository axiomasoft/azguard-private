---
id: D14
date: 2026-09-23
status: accepted
item: P6.2
items: [P4.2, P6, P6.1, P6.2, P7.1, P7.2]
supersedes: [D9]
superseded_by: null
---
# D14 — Exact identity with a feasible MySQL schema and explicit upgrade boundary

**Actor:** plan-design repair / Codex root
**Evidence:** `findings/design-audit-2026-09-23.md` A2; current 000002/000003/000005 migrations, MorphColumns and MySQL test config; official MySQL InnoDB index limits and functional-index documentation in `docs/reference.md`. D9 remains historical; its recovery, null-marker, quoting and review contracts continue where not narrowed here.

## Решение

Fresh install and recorded upgrade remain separate. Future-fresh 000003 defines `model_has_scopes.panel_id` as `VARCHAR(128)`, matching existing panel columns in role permissions/direct grants; 000005 uses the shared exact null-safe index helper. A new idempotent 000006 upgrades recorded databases: before any schema-changing DDL it inspects the actual column/table/engine, rejects non-null panel IDs longer than 128 without mutation or truncation, and rejects unsupported key capacity with an actionable diagnostic. Only then does it narrow the column, build and verify the exact replacement index, and remove the legacy index. Retry must converge after any allowed interruption. Existing values beyond 128 require an explicit consumer data migration/rename before retry; automatic truncation is forbidden.

Declared MySQL support for this exact index is non-MariaDB MySQL >=8.0.13 with InnoDB 16-KiB pages and DYNAMIC/COMPRESSED row format, under the current utf8mb4 schema and int/UUID/ULID morph variants. The key uses full `model_type` and `scope_entity_type` (255 characters each), bounded `panel_id` (128), full IDs, `role_id` and a non-null exact value plus marker for each nullable component. The worst declared UUID budget is approximately 2,852 bytes: `2×255×4 + 128×4 + 2×36×4 + 8 + 4` markers, below the documented 3,072-byte 16-KiB ceiling; actual emitted DDL and server configuration must still be tested. 8-/4-KiB pages, older row formats and other unsupported capacities fail explicitly before legacy-index removal. No prefix, truncation, probabilistic digest or application-only uniqueness is accepted.

P4.2 enforces the 128-character final panel-ID storage boundary on every official core/context/Filament/CLI write path even with `strict_panels=false`, before persistence; registration strictness remains opt-in. Raw consumer SQL is outside that API boundary and is checked by 000006 preflight. This public behavior change is documented in RU/EN upgrade/configuration docs and API/BC evidence. PostgreSQL 16+ uses `NULLS NOT DISTINCT`; SQLite and MySQL use full normalized value plus null marker for every nullable part. MariaDB remains unsupported without its own isolated DDL run. Identifiers are segment-validated and grammar-quoted. Context 000010 uses the configured table for both directions; moving an existing deployed table stays a consumer migration. Nullable `roles.class_name` uniqueness follows P5 cleanup. Dedupe is DB-side, transactional for destructive DML, maintenance-window only, and retry-safe. P6.1 has no review; P6.2 has one final `light` seam review; D4 remains the plan-wide design gate.

## Почему

The previous three default 255-character utf8mb4 strings alone consume 3,060 bytes, leaving too little for IDs and markers in a 3,072-byte key. A 128-character panel fits the existing adjacent schema and leaves room for exact full morph strings and declared IDs on a supported 16-KiB InnoDB profile. Explicit capability/data preflight makes deployed-schema incompatibility visible before destructive or narrowing DDL.

## Consequences

P6.2 owns 000003 fresh width, 000006 preflight/narrowing, emitted index parity and engine-profile tests. P4.2 owns the final-panel length guard; P7.1 verifies the declared MySQL profile and the overlength upgrade failure; P7.2 records compatibility and upgrade guidance. P6.1 retains rollback/dedupe ownership. An unsupported profile or overlength deployed value blocks upgrade safely; it is not represented as successful MySQL support.
