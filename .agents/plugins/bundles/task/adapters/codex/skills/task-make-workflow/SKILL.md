---
name: task-make-workflow
description: "Only when explicitly invoked. Explicit workflow authoring: fill a canonical template and validate its stages."
---

# Task make-workflow

Generated Codex adapter for semantic command `task:make-workflow`.

- Invocation: `$ task:make-workflow <arguments>`
- Routing: `implementation/high -> gpt-5.6-terra/high`
- Canonical source: `packages/task/commands/make-workflow.md`
- Canonical SHA-256: `sha256:d1bd1f94d90501e252b007d22a680d2fe51748e5786006e6afd9ffb5cd31f13a`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
