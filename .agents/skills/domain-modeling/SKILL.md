---
name: domain-modeling
bucket: architect
version: 0.2.0
description: "Active domain-model discipline: challenge terms, stress-test with scenarios, RECORD the glossary (CONTEXT.md) and ADRs INLINE as they crystallize (not in batches). Multi-context via CONTEXT-MAP.md; Rule of Two entry into the shared term-index; term steward model. Activate when clarifying terminology/ubiquitous language, recording an architectural decision, or when another skill builds a domain model (esp. fintech)."
risk: draft
persona: architect
tags: [domain, ddd, glossary, adr, ubiquitous-language]
requires: []
produces_for: [architecture, api-design, data-schema]
outputs: [CONTEXT.md, ADR]
snippets: [CONTEXT-FORMAT.md, ADR-FORMAT.md]
adapters: [claude, cursor, fable]
disable-model-invocation: false
sha256: ""
---

# Domain Modeling

Actively build and sharpen the project's domain model as you design. This is the *active* discipline — challenging terms, inventing edge-case scenarios, and writing the glossary and decisions down the moment they crystallise. (Merely *reading* `CONTEXT.md` for vocabulary is not this skill — that's a one-line habit any skill can do. This skill is for when you're changing the model, not just consuming it.)

## File structure

Most repos have a single context:

```
/
├── CONTEXT.md
├── docs/
│   └── adr/
│       ├── 0001-event-sourced-orders.md
│       └── 0002-postgres-for-write-model.md
└── src/
```

If a `CONTEXT-MAP.md` exists at the root, the repo has multiple contexts. The map points to where each one lives:

```
/
├── CONTEXT-MAP.md
├── docs/
│   └── adr/                          ← system-wide decisions
├── src/
│   ├── ordering/
│   │   ├── CONTEXT.md
│   │   └── docs/adr/                 ← context-specific decisions
│   └── billing/
│       ├── CONTEXT.md
│       └── docs/adr/
```

Create files lazily — only when you have something to write. If no `CONTEXT.md` exists, create one when the first term is resolved. If no `docs/adr/` exists, create it when the first ADR is needed.

> **Multi-repo (our case).** When a term is shared across several ecosystem projects (e.g. "fiscal
> document", "OFD", "cash receipt" — identical across all POS projects), record it in the shared
> ecosystem-level dictionary (mAInd hub / Brain), and the project's `CONTEXT.md` references it,
> adding only project-specific refinements.
>
> **Rule of Two (entry gate into the shared term-index).** A term enters the ecosystem's shared
> term-index ONLY when it has actually crossed the boundary of 2+ packages (precedent —
> `architect/package-contribution-protocol`: a need is confirmed by 2+ consumers, not one). A term
> living in a single package stays in its local `CONTEXT.md` — that's the norm, not an exception;
> the term-index is a thin index of cross-package overlaps, not a mega-glossary. Unsure whether a
> term crossed the boundary — don't add it: the gate is an entry gate, not a "just in case".
>
> **Path to the shared term-index.** `02-Knowledge/00-Strategy/Ecosystem_Glossary.md` — the path is
> given **relative to the Brain vault root**, NOT the current project's CWD. Designing outside Brain —
> first locate the Brain vault root (the ecosystem's mAInd hub / a known path), open the term-index
> there; a CWD-relative path silently collapses the reference into a no-op (same risk `axioma-stack`
> warns about for the radar).
>
> **Steward model.** The term's owner is the package whose `CONTEXT.md` governs it (steward). The
> term's definition is edited ONLY through the owning package's `CONTEXT.md`; the term-index in Brain
> does NOT store the definition itself — only a pointer line "term → owning package → collisions/
> homonyms → deprecated synonyms". Found a mismatch between the term-index and the owner's
> `CONTEXT.md` — `CONTEXT.md` is the source of truth, the term-index is stale and must be updated to match it.

## During the session

### Challenge against the glossary

When the user uses a term that conflicts with the existing language in `CONTEXT.md`, call it out immediately. "Your glossary defines 'cancellation' as X, but you seem to mean Y — which is it?"

### Sharpen fuzzy language

When the user uses vague or overloaded terms, propose a precise canonical term. "You're saying 'account' — do you mean the Customer or the User? Those are different things." (Fintech: "operation" / "payment" / "transfer" / "authorization" mean different things across banking APIs — don't conflate them.)

### Discuss concrete scenarios

When domain relationships are being discussed, stress-test them with specific scenarios. Invent scenarios that probe edge cases and force the user to be precise about the boundaries between concepts.

### Cross-reference with code

When the user states how something works, check whether the code agrees. If you find a contradiction, surface it: "Your code cancels entire Orders, but you just said partial cancellation is possible — which is right?"

### Update CONTEXT.md inline

When a term is resolved, update `CONTEXT.md` right there. Don't batch these up — capture them as they happen. Use the format in [CONTEXT-FORMAT.md](snippets/CONTEXT-FORMAT.md).

`CONTEXT.md` should be totally devoid of implementation details. Do not treat `CONTEXT.md` as a spec, a scratch pad, or a repository for implementation decisions. It is a glossary and nothing else.

**Update triggers.** Edit `CONTEXT.md` on any of these events, without delay:

- a new aggregate, entity, or value object appears in the model;
- an existing term is renamed (even locally, within a single PR);
- a term splits into two distinct concepts, or two terms collapse into one.

**PR-sync rule.** The glossary edit ships in the SAME PR/commit as the code that triggered it — not
as a separate "doc" afterward. A glossary lagging the code by even one PR has already started rotting.

**"Asked it — it's stale" rule.** If during the session you had to ask the user what a `CONTEXT.md`
term means (rather than defining a new term for the first time), that's a signal the glossary no
longer reflects the team's current understanding. Record the clarified definition immediately after
getting the answer — don't defer it.

### Offer ADRs sparingly

Only offer to create an ADR when all three are true:

1. **Hard to reverse** — the cost of changing your mind later is meaningful
2. **Surprising without context** — a future reader will wonder "why did they do it this way?"
3. **The result of a real trade-off** — there were genuine alternatives and you picked one for specific reasons

If any of the three is missing, skip the ADR. Use the format in [ADR-FORMAT.md](snippets/ADR-FORMAT.md). (Domain example: "why libfptr10 via dart:ffi, not shell" — hard to reverse, surprising without context, a real choice was made.)

## Conformance

- New aggregate/entity/term rename — edit `CONTEXT.md` in the SAME PR, not a separate doc afterward.
- Asked the user what a `CONTEXT.md` term means — the glossary is stale, record the clarified definition immediately.
- `CONTEXT.md` — domain language only; zero implementation details, specs, or decisions.
- A term enters the shared term-index (`Ecosystem_Glossary.md`) only when it provably crossed 2+ packages (Rule of Two); unsure — don't add it.
- The path to the term-index is relative to the Brain vault root, not the project's CWD.
- A term's definition lives ONLY in the owning package's `CONTEXT.md` (steward); the term-index is a pointer, not a definition store.
- Term-index vs. owner's `CONTEXT.md` mismatch — `CONTEXT.md` is the source of truth, fix the term-index to match it.

## Related skills

- `architect/architecture` — ADR process at larger scale (system-wide architectural decisions); domain-modeling gives the inline format and language, architecture gives the full decision discipline.
- `laravel-architecture/modular-architecture` — applying DDD/bounded-context in Laravel (modular monolith).
- `general/grill-with-docs` — grilling session that invokes this skill and builds CONTEXT.md/ADR on the fly.
- `architect/api-design`, `architect/data-schema` — consume the recorded glossary (this skill `produces_for` them).

<!-- ru-source-sha256: 503bd4c5cfff45d93dc195629bddba62ed687bb1406d12fea22a405b1ff4e161 -->
