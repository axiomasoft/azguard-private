---
id: D12
date: 2026-09-23
status: accepted
item: P7
items: [P1, P2, P3, P4, P5, P6, P7]
supersedes: []
superseded_by: null
---
# D12 — Global batching and one execution sheet

**Actor:** plan-designer/Codex root
**Evidence:** owner clarification in the active finish invocation; compiled Routing from
`plan.md`; P1–P7 dependency and input graph; `roadmap.md` execution-sheet/v1.

## Решение

Phase design may propose local batches, but design finish re-evaluates them across the
whole plan. A batch stays phase-local and adjacent, cannot cross audit/close or authority
boundaries, and is retained only where one session reuses producer/consumer context,
reads, setup or validation. P1.1 and P1.2 remain solo because P1.2 consumes the sealed
identity result and owns a distinct expiry/envelope seam. B2–B7 retain their phase-local
chains.

Every final admission has one exact row in `roadmap.md` under
`execution-sheet/v1`. Its launch route is the maximum semantic model class and effort of
all members; its review is the maximum member review. Provider-specific selectors are
not plan data: the provider adapter maps the unified semantic route to one model for the
whole admission. `handoff.md` selects an eligible execution-sheet row and never invents
a different group or a cheaper route.

## Почему

Per-phase design has the best local context, while finish can see conflicts and reuse
across the complete dependency graph. A single validated execution sheet prevents an
agent from launching the first item at a cheaper model even though another item in the
same admission requires a higher one, and prevents grouping from being reconstructed
inconsistently from scattered phase prose.

## Consequences

Future launch recommendations are copied from `roadmap.md`. Any Routing, membership,
model-class/effort or review change invalidates the finish check until the sheet is
updated. Concrete model names such as provider version selectors are rejected from the
semantic Routing table.
