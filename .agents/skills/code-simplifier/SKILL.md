---
name: code-simplifier
bucket: quality
version: 0.3.0
description: "Simplify freshly written code without changing behavior: dead code, needless abstractions, dups, align to project patterns; YAGNI ladder before coding + diff delete-list (ponytail discipline). Tests pin behavior."
risk: write
persona: quality
tags: [quality, refactoring, refactor, ci, validation]
requires: []
produces_for: [code-review]
outputs: []
snippets: ["simplify-on-push.yml"]
adapters: [claude, cursor, fable]
sha256: ""
---

# Code Simplifier

## When to activate
- Right after implementing a feature/fix — to the **fresh diff** of current branch/PR, not legacy.
- Planned refactor of old code → `refactoring-plan`. Debt inventory → `tech-debt-audit`.

## Hard constraints
- **No behavior change.** Tests are the gate: green before, green after, no test edited. No test on the touched area → don't simplify.
- **No business-logic changes.** Form only, not meaning.
- **No new dependencies.** Simplifying via a new package is not simplification.
- **No public-contract changes:** public method signatures, API, data format, event names.

## Simplification checklist (per diff)
1. **Dead code:** unused vars, imports, branches, params, commented-out blocks.
2. **Needless abstractions:** interface with one impl "for the future", one-line wrapper, indirection with no second consumer.
3. **Dups:** fragment repeated within diff, or copy of an existing project util — use the existing one, don't create new.
4. **Conditionals:** early returns over nesting, invert guard conditions, merge identical branches.
5. **Project patterns:** diff follows surrounding-code conventions (style, naming, layers), not its own.
6. **Comments:** drop comments that restate code; keep only those explaining non-obvious constraints.

## Speculative-generality checklist (YAGNI)
Separate diff pass against grow-into abstractions. Any "yes" → simplify (remove abstraction / inline / keep in one place):
- Interface/contract with a **single** impl and no second consumer?
- Param/option never passed with a value other than default?
- `abstract`/base class with **one** subclass?
- New public method/hook/event used in **only one** place?
- Indirection layer (factory/builder/adapter) added with no second variant?

Same markers = reason NOT to extract into a shared package (see `architect/package-contribution-protocol`, Rule of Three): generalize on the third repeat, not an imagined future.

## YAGNI ladder — BEFORE coding (ponytail discipline)
Before adding code, stop at the FIRST rung that holds — don't climb higher:
1. Needed at all? No → skip (YAGNI).
2. Already in the codebase? → reuse.
3. Does stdlib do it? → use stdlib.
4. Native platform/framework feature? → use it.
5. Solved by an already-installed dependency? → use it (do NOT add a new one).
6. One line? → one line.
7. Only then — the minimum that works.

Ladder applies AFTER understanding the task, not instead of it. Bug → find root cause: grep every caller of the touched function, fix the shared cause once, not the symptom.

## Output format — delete-list
Per diff, return a tagged list (what to remove/simplify and what replaces it):

```
L12-38: stdlib: 27-line email validator → native check, 1 line.
L4: native: library import for one call → native API, 0 deps.
L88: yagni: abstraction with one impl → inline until a second appears.
L52-71: delete: retry wrapper around an idempotent local call → remove.
net: -47 lines possible.
```

Tags: `delete:` / `stdlib:` / `native:` / `yagni:` / `shrink:`. Principle: "deletion > addition, boring > clever" — less code, behavior and safety preserved.

## Process
1. Get diff: `git diff <base>...HEAD` (or files from task).
2. Run checklist, collect edits each with rationale.
3. Apply edits, run tests and lint.
4. If an edit is debatable (could read as behavior change) — don't apply; propose separately.

## Background mode
`snippets/simplify-on-push.yml` — GitHub Actions template: agent runs this checklist on the push diff and leaves suggestions as a PR comment. Background agent **suggests**, does not merge. See docs/workflows/background-agents.

## Links
- `quality/refactoring-plan` — planned refactor of old (legacy) code, not fresh diff
- `quality/tech-debt-audit` — inventory and prioritize debt before refactoring
- `quality/code-review` — fresh-diff simplification feeds `[suggestion]`/`[nit]` into review
- `quality/test-strategy` — tests as gate: no coverage of touched area → don't simplify
- `system/cross-project-coordinator` — cross-project dups (util copy living in another repo)
- `architect/package-contribution-protocol` — same YAGNI/Rule-of-Three for extracting code into a shared package

<!-- ru-source-sha256: bc79740ce9c5c59c6036b54fc0c7a2f9ffa51e3b4d11b647d64973afbd93adaa -->
