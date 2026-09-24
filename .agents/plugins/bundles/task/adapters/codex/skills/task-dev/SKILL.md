---
name: task-dev
description: "Only when explicitly invoked. Implement an authorized feature directly, with checks proportionate to risk."
---

# Task dev

Generated Codex adapter for semantic command `task:dev`.

- Invocation: `$ task:dev <arguments>`
- Routing: `implementation/high -> gpt-5.6-terra/high`
- Canonical source: `packages/task/commands/dev.md`
- Canonical SHA-256: `sha256:ee2b29790bd36d9861240fbe71a94b35ddbc2092e7f64dc5970cc723dcd804c2`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
