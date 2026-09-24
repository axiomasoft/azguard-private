---
name: context-economy
bucket: general
version: 1.3.0
description: "Reduce measured context overhead with targeted reads, on-demand instructions, continuity and honest usage evidence."
when_to_use: "Use when a session's context is bloated or growing fast."
risk: read
persona: oss-dev
tags: [tokens, context, claude-code, workflow, conventions]
requires: []
produces_for: [gh-review]
outputs: [".claude/rules/*.md", ".claude/commands/*.md", "CLAUDE.md (trim)"]
snippets:
  - rules-example.md
  - command-prime.md
  - command-plan.md
  - command-execute.md
  - claude-md-checklist.md
adapters: [claude, cursor, fable]
sha256: ""
---

# Context economy

Keep unusual repository invariants, source ownership and exact build commands in
resident instructions. Load topic-specific guidance only when it helps the task.
Read targeted code, diffs and logs; retain enough context to assess dependencies.

Continue in the current root while context and capabilities suffice. A phase,
command or directory change alone does not require a restart. For executable
plans, `general/plan-protocol` and `task.plan_continuity` own continuity. Respect
an explicit request for separate design/review. Before a necessary handoff,
record decisions, rejected alternatives, unresolved work and exact next inputs.
Missing optional usage telemetry is visible uncertainty, not a repair task.

Delegate only useful independent work or required independent review. Count the
child's reads and usage as work too. Choose tools by available capability and
measured payload; neither MCP nor CLI is universally cheaper. Use `rg`, symbols,
or graph navigation according to the question and source freshness.

Measure before/after on a matched task. Report actual commands, reads, restarts
and elapsed time. Token claims require provider usage; otherwise say unmeasured.
Line counts and hypothetical sums of skill bodies are not token savings.

Provider-specific setup examples live in [snippets/](snippets/); check the installed
provider's documentation before applying them. They are optional templates,
not mandatory session boundaries. `session-handoff` covers durable handoff content.

## Related skills

- `general/verify-claims` — external-premise verification.
- `general/context-economy` — measured context and continuity.
