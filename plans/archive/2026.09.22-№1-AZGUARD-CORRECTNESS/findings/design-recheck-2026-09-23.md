# Plan-wide design recheck — 2026-09-23

**Verdict: GREEN for design, not implementation.** This is the bounded recheck of
A1–A3 from `design-audit-2026-09-23.md` after design repairs. No product code,
database migration, Redis scenario or P1–P7 item was executed.

| Finding | Closure evidence |
|:--|:--|
| A1 — uncommitted revision cached | D13 supersedes D5. P2.2, P2.3, P2 dossier and examples require transaction-level bypass of all reusable tiers, fresh same-connection reads and the 7→uncommitted 8→rollback→unrelated committed 8 regression with a second reader. |
| A2 — exact MySQL key cannot fit | D14 supersedes D9. P6.2 now owns fresh 000003 `panel_id` 128, guarded deployed 000006 narrowing, engine/data preflight before schema-changing DDL, full-width emitted-index tests on the declared 16-KiB InnoDB profile and explicit unsupported verdicts for smaller budgets. P4.2 enforces the write boundary and P7.1 qualifies it. Current migrations/`MorphColumns` and official MySQL limits were cross-checked; the ~2,852-byte UUID estimate is a design budget, not a DDL pass. |
| A3 — stale skeleton handoff | Root `plan.md` and P1–P3 handoffs now describe detailed, execution-ready items gated by GREEN design audit. P1–P6 test/Required Reads language is present-tense. Historical decision and RED audit records remain unchanged in meaning. |

The PostgreSQL P6 example also now uses actual `scope_entity_type`,
`scope_entity_id` and `panel_id` columns. Active item inputs reference accepted
D13/D14, while generated decision views retain superseded D5/D9 as history.

## Validation boundary

- `plan-lint.py`: 0 errors, 0 warnings after repairs.
- `plan-views.py`: decisions, status, all item states and materialized P1 bundles
  regenerated through their owner tool; freshness checks performed after final handoff.
- The static MySQL budget assumes current utf8mb4 columns, declared int/ULID/UUID
  variants and the official 16-KiB/DYNAMIC-or-COMPRESSED ceiling. P6.2 must still
  compile and run actual DDL on an isolated supported server. This is an
  implementation acceptance check, not a residual design ambiguity.
- The installed Codex CLI and Task adapter establish launch syntax and model
  mapping. The OpenAI manual helper failed DNS resolution on 2026-09-23; no
  current remote Codex documentation verification is claimed.

P1.1 is the first eligible execution item after this design verdict. Any failed
implementation regression returns to its owning item; this recheck does not
pre-approve product behavior or a release.

## Routing-only correction after GREEN

The owner subsequently restricted execution providers to Composer 2.5, Grok
4.6/4.7 and emergency GPT‑5.6 Sol, reserving GPT‑6 Sol for audits. Semantic
Routing and the execution sheet were amended together without changing item
behavior, dependencies, Files or Validation. See
`model-routing-correction-2026-09-23.md`; its Task Cursor CLI-adapter defect
is an execution-admission limitation, not proof of a product regression.
