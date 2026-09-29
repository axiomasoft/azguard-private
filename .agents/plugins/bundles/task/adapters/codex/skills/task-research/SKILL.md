---
name: task-research
description: "Only when explicitly invoked. Research an explicit question using verified sources; return findings and Next."
---

# Task research

Generated Codex adapter for semantic command `task:research`.

- Invocation: `$ task:research <arguments>`
- Routing: `frontier/high -> gpt-6-astra/high`
- Canonical source: `packages/task/commands/research.md`
- Canonical SHA-256: `sha256:496ce8ae1b95b6df6c4f25578178abc103713742d8080edf91606597ba7d82c6`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
