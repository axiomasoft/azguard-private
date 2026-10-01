---
name: task-review
description: "Only when explicitly invoked. Risk-scaled read-only review of code, docs or specs."
---

# Task review

Generated Codex adapter for semantic command `task:review`.

- Invocation: `$ task:review <arguments>`
- Routing: `implementation/high -> gpt-6-sol/high`
- Canonical source: `packages/task/commands/review.md`
- Canonical SHA-256: `sha256:cd4c5f775b74c069edf6b4ca1cf483d970d14dbcd114f6a6dc015e2ae51b7b90`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
