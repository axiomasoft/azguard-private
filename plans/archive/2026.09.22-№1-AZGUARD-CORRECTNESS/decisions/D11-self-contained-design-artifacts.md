---
id: D11
date: 2026-09-23
status: accepted
item: P7
items: [P1, P2, P3, P4, P5, P6, P7]
supersedes: []
superseded_by: null
---
# D11 — Self-contained design artifacts are execution inputs

**Actor:** plan-designer/Codex root
**Evidence:** owner direction in the active Task invocation; comparison with
`plans/2026.09.19-№1-COREX-PLATFORM-HARDENING`; repository evidence and retrieval
captured under `research/` and `artifacts/`. External search output is treated as
untrusted supporting data and was checked against repository or primary-source evidence where available.

## Решение

Every P1–P7 phase carries a self-contained implementation dossier
`research/Pn-design.md`, a retrieval/verification boundary
`research/Pn-design-rag.md`, and concrete worked examples in
`artifacts/Pn-design/examples.md`. Every item in that phase names all three as
`Inputs`, so the generated execution bundle digest-binds them. A phase index links
the same carriers plus the raw capture or explicit no-search record under
`artifacts/Pn-design-rag/`.

Raw Perplexity/search output is preserved verbatim enough to audit what the design
model saw, but it is non-normative. Accepted decisions, repository evidence, and
verified primary sources win on conflict. A phase with no changing external premise
records why no search was needed instead of fabricating a capture. The dossiers own
the expanded code/dependency map, algorithms, failure modes, worked examples, and
validation matrix; phase and item contracts remain the routing and acceptance layer.

## Почему

The prior representation compressed a large amount of design reasoning into phase
prose and one findings file. It was understandable to the authoring model but forced
an execution model to reconstruct hidden connections. Making the detailed carriers
explicit and binding them through `Inputs` preserves that reasoning across weaker
models, fresh sessions, and generated bundles without turning raw search synthesis
into authority.

## Consequences

Design finish must fail if a phase lacks one of these carriers or an item does not
reference its normative dossier, verification note, and worked examples. Changes to
those artifacts invalidate affected bundle digests. Economy limits apply to phase/item
contracts, not to the supporting dossier required for self-contained execution.
