# Внешние подтверждения P7 — Composer and tag workflow

Сырой capture: `../artifacts/P7-design-rag/capture.md`. Direct web adapter failed in this
session; primary Composer/GitHub claims are also present in repository configuration and official
links captured by the search. Recommendation about changelog ordering remains an internal release
contract, not an external guarantee.

## Evidence ledger

- Composer resolves version constraints against the entire dependency/platform graph; declared
  ranges do not prove every intended combination installable.
- `on.push.tags` runs for matching tag refs; the workflow observes the commit pointed to by the
  pushed ref. A later commit to main is not retroactively part of that tagged tree.
- Tags can be moved/force-updated operationally, so immutability is policy, not the reason for the
  plan. The correctness reason is simpler: publication must validate the exact candidate tree.

## Applied inference

AzGuard requires the reviewed root changelog section inside the candidate commit before owner
creates a tag. A tag-triggered job that writes a new changelog commit to main is too late for that
tag. This is D10's local release policy, verified by read-only git-tree fixture tests.

