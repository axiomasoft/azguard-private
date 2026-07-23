---
name: session-handoff
bucket: general
version: 0.2.0
description: "Cross-session/agent context handoff: compress a session into a structured doc — state, decisions, open items, next steps."
risk: read
persona: oss-dev
tags: [workflow, context, tokens, handoff]
requires: []
produces_for: []
outputs: [".claude/handoffs/*.md"]
snippets: ["handoff-template.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

# Session Handoff

## Activate when
- Session context near limit (or compaction imminent).
- Work handed to another agent (Claude → Cursor, local → CI).
- `anti-drift` hard threshold tripped — end session, restart.
- End of workday on a multi-day task.

## Protocol
1. **Gather state.** `git status` + `git diff --stat`: changed / committed / in-progress. From the repo, not memory.
2. **Record decisions.** Each decision with rationale ("chose X because Y; considered Z, rejected for W"). A decision without "why" is useless.
3. **List open items.** Open questions, known bugs, deferred edits — note what blocks each.
4. **Next steps.** Concrete actions in priority order, with file paths and verification commands.
5. **Read-from-scratch check.** Reread as a context-less agent: understandable without this session? Codenames/abbreviations introduced this session — expand or remove.

## Doc structure
Template: `snippets/handoff-template.md`. Save to the standard path `.claude/handoffs/YYYY-MM-DD-<topic>.md` (auto-indexable by maind) or pass in first message of new session.

If work follows a master plan `plans/<ID>/` (see `plan-protocol`) — this template
does not apply: handoff follows the plan schema in `plans/<ID>/handoff.md`,
NOT `.claude/handoffs/`.

## Anti-patterns
- Summary from memory without `git status`/`git diff`.
- Listing done work without "why" on decisions.
- Dumping whole session log — this is compression, not a transcript.

## Related skills
- `plan-protocol` (general) — multi-phase work under a master plan `plans/<ID>/`:
  handoff follows its schema in `plans/<ID>/handoff.md`, this skill does not apply.
- `anti-drift` (general) — after hard threshold, session ends with this handoff.
- `context-economy` (general) — handoff before `/clear` as part of Plan→Clear→Execute.
- `complex-task-orchestrator` (general) — open subtasks and risks go into the handoff.
- `system/shared-memory` — if projects share memory, key facts go there (remember), not only the handoff file.

<!-- ru-source-sha256: f4f2dc1f77dfc591ceb06d2acb89d898f6ab86bd6f853ad7fa36ee9d67781262 -->
