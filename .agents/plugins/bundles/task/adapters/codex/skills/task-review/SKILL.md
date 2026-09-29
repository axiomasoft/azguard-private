---
name: task-review
description: "Only when explicitly invoked. Risk-scaled read-only review of code, docs or specs."
---

# Task review

Generated Codex adapter for semantic command `task:review`.

- Invocation: `$ task:review <arguments>`
- Routing: `implementation/high -> gpt-6-sol/high`
- Canonical source: `packages/task/commands/review.md`
- Canonical SHA-256: `sha256:c04416db5b4c7e82a9fc2194453ea1c4c05809ef6fd0f425a1f0f929c315d40c`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
