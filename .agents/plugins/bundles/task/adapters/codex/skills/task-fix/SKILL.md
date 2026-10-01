---
name: task-fix
description: "Only when explicitly invoked. Fix a scoped defect directly; preserve existing work and verify the change."
---

# Task fix

Generated Codex adapter for semantic command `task:fix`.

- Invocation: `$ task:fix <arguments>`
- Routing: `implementation/medium -> gpt-6-sol/medium`
- Canonical source: `packages/task/commands/fix.md`
- Canonical SHA-256: `sha256:985bb315d8635899baf69d0ed9758e459128c9cbe4e20abb08c9db39d9bc9308`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
