---
name: task-make-workflow
description: "Only when explicitly invoked. Explicit workflow authoring: fill a canonical template and validate its stages."
---

# Task make-workflow

Generated Codex adapter for semantic command `task:make-workflow`.

- Invocation: `$ task:make-workflow <arguments>`
- Routing: `implementation/high -> gpt-6-sol/high`
- Canonical source: `packages/task/commands/make-workflow.md`
- Canonical SHA-256: `sha256:eec5bc213f3ca2ac5746bf4dfda7a9b47dd2358a6bd061689734d711bf40cb8f`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
