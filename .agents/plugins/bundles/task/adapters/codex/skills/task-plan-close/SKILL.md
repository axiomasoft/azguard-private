---
name: task-plan-close
description: "Only when explicitly invoked. Reconcile GREEN Task items or phases; ordinary execution already closes inline."
---

# Task plan-close

Generated Codex adapter for semantic command `task:plan-close`.

- Invocation: `$ task:plan-close <arguments>`
- Routing: `implementation/low -> gpt-6-sol/low`
- Canonical source: `packages/task/commands/plan-close.md`
- Canonical SHA-256: `sha256:03098a126a5da720830263c8e5149e0f082b227c9a02261c107b2fdf20ad386a`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
