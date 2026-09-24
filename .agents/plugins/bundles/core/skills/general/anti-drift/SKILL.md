---
name: anti-drift
bucket: general
version: 0.6.0
description: "Recover from repeated failed approaches or growing scope; preserve authorized progress."
when_to_use: "Use when a session drags, retries approaches, or piles on flags."
risk: read
persona: oss-dev
tags: [workflow, discipline, orchestration, context]
requires: []
produces_for: [session-handoff]
outputs: []
snippets: ["claude-md-anti-drift.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

# Anti-Drift

## When to activate

Activate when repeated attempts stop producing evidence, the solution accumulates speculative
layers, or earlier decisions are getting lost. Ordinary edits need no extra process.

## Keep the result in view

- State the authorized outcome and the next useful check. Use existing acceptance criteria;
  a reversible documentation edit need not acquire a new test suite.
- Resolve routine implementation choices from context. Ask only for a missing decision that
  changes scope, public behavior, authority, or an irreversible action.
- Edit the files necessary to achieve that outcome, including an owning source or test absent
  from the initial file list. Avoid unrelated cleanup; preserve others' changes.
- Complete necessary implementation, verification, and delivery within existing authority.
  A green intermediate check is not completion of an unfinished requested result.

## Recover instead of repeating

After roughly ten investigative calls with no conclusion, summarize the evidence and choose
one small reversible experiment. After repeated failed tactics (roughly twenty iterations is
an alarm, not a quota), checkpoint what failed and change the hypothesis or narrow the case.
Continue in the current session while context and tools suffice. A new session needs a real
context/capability boundary or owner request; counts and file breadth do not require one.

Before adding a dependency or abstraction, check whether existing code solves the problem.
An authorized necessary change may proceed; a new product capability or package contract
outside scope needs a decision. For dependent packages, inspect the owning source and existing
extension points. An authorized package bugfix proceeds there with qualification; an unrelated
API expansion does not follow automatically. See `architect/package-contribution-protocol`.

## File and tool hygiene

Read the affected content and current diff before editing. Reuse unchanged content already in
context; refresh when another writer or tool could have changed it. Use unique edit anchors.
Never revert a whole file to remove your own out-of-scope fragment if it contains others' work.

A permission denial is evidence about authority. Do not retry the denied action in disguised
syntax. Use an alternative only when the stated policy permits that action (for example, the
owning publisher instead of manual removal); otherwise report the reason and continue
independent authorized work. Do not bypass a denial through another tool or agent.

Shared servers, browser profiles, sockets, and locks may belong to live sessions. Do not kill
or delete them to clear contention. Queue, isolate your context, or use the owning recovery
mechanism; restart only with established authority and no conflicting users. Retry transient
reads with bounded backoff. Before retrying a write, establish whether it already took effect
or use an idempotent operation.

## Related skills

When the result is complete, report it with checks and limitations. Continue a ready successor
only within the authorized scope; otherwise name the useful next action without inventing more
mandatory work. `general/session-handoff` handles an actual handoff;
`general/context-economy` handles context pressure. The optional
[project snippet](snippets/claude-md-anti-drift.md) is installed only through the project's
owned source and measured resident-context policy.
