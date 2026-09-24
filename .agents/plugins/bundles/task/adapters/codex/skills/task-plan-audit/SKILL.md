---
name: task-plan-audit
description: "Only when explicitly invoked. Read-only Task design or phase audit; phase audit needs owner-bound consent."
---

# Task plan-audit

Generated Codex adapter for semantic command `task:plan-audit`.

- Invocation: `$ task:plan-audit <arguments>`
- Routing: `frontier/xhigh -> gpt-5.6-sol/xhigh`
- Canonical source: `packages/task/commands/plan-audit.md`
- Canonical SHA-256: `sha256:03aec4d7e720de6c50f4c10b7d2ecf8d596cca9077d8b6940e096a255be06f83`
- Runtime projection: `command.md` (generated and provenance-locked; never edit it as source)

Activate only when the owner explicitly invokes this Task command or skill. Task is not an
automatic workflow for development, fixes, design, or a repository containing plans.

Read `command.md` completely and execute it with the invocation payload as `$ARGUMENTS`.
Preserve the semantic command ID, gates, status literals, scope, and validation behavior.
