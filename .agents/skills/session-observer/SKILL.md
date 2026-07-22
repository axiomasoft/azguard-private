---
name: session-observer
bucket: system
version: 0.3.0
description: "EXPERIMENTAL (enable per-project). Post-hoc Claude Code session analysis from JSONL logs — finds weak spots on 3 axes: agents (skill-skip, drift, loops), skills (gaps, dead weight, thin), settings (hooks, permissions). Findings → learnings via memory-hook, human promotes. Activate AFTER a work cycle / at session end / on request «analyze session» — only if enabled in project."
risk: read
persona: architect
tags: [observer, session-analysis, learning, jsonl, drift, self-improvement, post-mortem]
requires: [shared-memory]
produces_for: []
outputs: []
snippets: ["analyze-session.md"]
adapters: [claude]
sha256: ""
---

# Observer — post-hoc session analysis

Read session logs after work, find systemic problems (drift, skill skips, loops, repeated errors), write learnings, human promotes them to memory/rules/skills.

## Activate when
- After a finished work cycle (implementer + reviewer done).
- At session end, before `/clear`.
- On explicit request «analyze session / what went wrong».

## Enablement — experimental, per-project
Default **OFF**. Per-project, nothing global.
**Before analyzing, check the flag** `.claude/observer.env.ini` → `ENABLED`: if not `true` → say «Observer off; enable — /observe on», do nothing.

### `/observe on` — Claude runs ALL steps automatically
`ENABLED=true` alone does NOT activate the logger — full set required. Run in order (each merge-only, safe to repeat):
1. **Hook files globally** — if `~/.claude/hooks/observer/observer.sh` absent: `harness hooks --global` (or `<harness>/scripts/apply-permissions.sh --global`).
2. **Wire into project** — `<harness>/scripts/apply-permissions.sh --target . --preset observer` (adds PreToolUse/PostToolUse/Stop → observer.sh in `.claude/settings.local.json`).
3. **Flag** — write `.claude/observer.env.ini` → `ENABLED=true`.
4. **gitignore** — add `.claude/observer/` to project `.gitignore`.
5. **Tell user to restart** — hook activates at session START, not on `/clear`. To capture a run, **restart the session** (not just clear); else subagents run but `runtime.jsonl` stays empty — then do post-hoc analysis on session-JSONL (`/observe session` still works).

### `/observe off`
`.claude/observer.env.ini` → `ENABLED=false`. Hook stays wired but instant no-op (writes nothing). No need to remove wiring.

## Can / cannot
- **Post-hoc only.** Live monitoring of other agents in real time is impossible in Claude Code (no call stream to observer). Analyze already-written JSONL logs. Never promise real-time watching.
- **Read-only on code.** Writes ONLY learnings (via memory-hook) and, on approval, to `.claude/rules/` or a skill proposal. Never touches app code.

## Data sources
Project dir in logs = project path with `/`→`-` (`~/.claude/projects/-home-...-<project>/`):
- `<proj>/<session-id>.jsonl` — main stream (user/assistant/tool calls, errors).
- `<proj>/<session-id>/subagents/agent-*.jsonl` — isolated subagent logs.
- `git -C <project> diff HEAD` — what actually changed in code.
- `.claude/observer/runtime.jsonl` + `archive/*.jsonl` — if **capture layer** on (harness hook `observer`, PreToolUse/PostToolUse/Stop): compact project-local log with ready alerts (`LOOP`). Enable: `/observe on` + `harness hooks --preset observer`.

Exact jq recipes per dimension — `snippets/analyze-session.md`.

## Weak-spot analysis — 3 axes
Use only what JSONL/diffs give reliably (live and «why agent decided» — no; BOOTSTRAP_DRIFT/CONTEXT_LOSS removed by `skills:`-preload). Exact jq recipes — `snippets/analyze-session.md`.

### Axis 1 — agents
- **SKILL_COMPLIANCE** — was a preloaded-skill rule violated. Grep `git diff` for anti-patterns (Laravel: `::query(|::create(` in controller) — catches exactly the failure this exists for.
- **LOOP_DETECTION** — file read N+ times / one error repeats (counter over Read).
- **SCOPE_DRIFT** — write outside the task's expected zone.
- **INTER_AGENT_ERRORS** — reviewer returned `needs-work`; tool_result `is_error`.

### Axis 2 — skills
- **SKILL_GAP** — task/domain with no matching skill → candidate for new one.
- **THIN_SKILL** — skill loaded but failure still happened → content gap (e.g. `php:laravel` was 35 lines). Signal «beef up skill».
- **DEAD_SKILL** — distributed but never loads → dead weight.
- **CORRECTION_AFTER_LOAD** — many corrections after loading a skill → unclear/wrong.

### Axis 3 — settings
- **HOOK_MISCONFIG** — gate/hook off where needed (review-gate `off`).
- **PERMISSION_FRICTION** — repeated permission prompts on one pattern → allowlist candidates (pairs with `fewer-permission-prompts`).
- **CORRECTION_LOG** — user replies («no», «not like that», «use X») as cross-axis signal of a missing rule/setting.

**Closed loop:** findings feed back — Axis 1 → strengthen binding/gate, Axis 2 → beef up/create skill, Axis 3 → fix hooks/permissions. System fixes itself.

## Output & promotion (human decides)
1. Per-dimension summary with `file:line` where possible (table: critical / warn / observation).
2. Learnings — NOT a custom file, but via existing memory-hook: `~/.claude/hooks/memory/memory.sh remember --type project "<fact + why>"`. Queue = unpromoted `remember` / project-local staging, viewed on request «show learnings».
3. Promotion (on user approval, in place):
   - one-off context → `memory.sh remember` / project memory;
   - recurring correction → rule `.claude/rules/<topic>.md` (native path-scoped mechanism, loads by `paths:`);
   - confirmed skill-gap → skill **proposal** under `skill-authoring` / `package-contribution-protocol` (never auto-write a registry skill).
4. Recall link: promoted items return via `memory.sh recall "<topic>"`.

## Checklist
- [ ] Read main JSONL + subagent + `git diff HEAD`.
- [ ] Each dimension has a verdict (or explicit «clean»).
- [ ] SKILL_COMPLIANCE checked by grepping anti-patterns over the diff.
- [ ] Learnings written via `memory.sh remember`, not a custom file.
- [ ] Promotion only on human approval; skills not auto-written.
- [ ] No live-watching promised (post-hoc only).

## Related
- Shared memory (recall/remember, backends) → `system/shared-memory`.
- Node topology → `system/local-topology`.

<!-- ru-source-sha256: 4bc93cd73dbbf5e8fb758c0c5dbd0b41ea61b35892a2cb22029b6b2f6bb3cc83 -->
