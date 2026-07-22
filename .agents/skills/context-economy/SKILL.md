---
name: context-economy
bucket: general
version: 1.2.1
description: "Claude Code context economy in a project: CLAUDE.md ≤200 lines, path-scoped .claude/rules/, /compact vs /clear, Plan→Clear→Execute, MCP audit, model routing. Officially-supported mechanisms only — no myths like .claudeignore."
risk: read
persona: oss-dev
tags: [tokens, context, claude-code, workflow, conventions]
requires: []
produces_for: [gh-review]
outputs: [".claude/rules/*.md", ".claude/commands/*.md", "CLAUDE.md (trim)"]
snippets:
  - rules-example.md
  - command-prime.md
  - command-plan.md
  - command-execute.md
  - claude-md-checklist.md
adapters: [claude, cursor, fable]
sha256: ""
---

# Context Economy — token-spend discipline

Reference: https://code.claude.com/docs/en/memory. Do NOT exist / do NOT work: `.claudeignore` (not a thing in Claude Code); `@import` in CLAUDE.md = organization not economy (imports load fully at start).

## 1. CLAUDE.md — ≤200 lines (Anthropic official target)

- Keep: non-standard build/test commands, architecture decisions against framework defaults, prohibitions, paths to skills/rules.
- Remove: what the model knows from training (standard Laravel/TS), aspirational rules ("write clean code"), contacts, schedules.
- Per-line test: "would this rule surprise an experienced dev new to the repo?" No → remove.
- Human notes → HTML comments `<!-- ... -->`: stripped before context injection (preserved inside code blocks).
- Audit checklist: snippets/claude-md-checklist.md.

## 2. Path-scoped rules — `.claude/rules/*.md`

Rules with `paths:` frontmatter load only when Claude reads matching files; without `paths:` → every session like CLAUDE.md:

```markdown
---
paths:
  - "app/Filament/**"
---
# Filament rules — load only when working with Filament files
```

Template: snippets/rules-example.md. Not skills: SKILL.md already loads lazily (only name+description live in session context); `paths:` for skills is unneeded and does not exist.

## 3. Sessions: /compact, /clear, Plan→Clear→Execute

- `/compact` — after finishing a feature (summarizes history, keeps decisions).
- `/clear` — on topic switch (full reset).
- Large tasks → separate sessions: plan to file (`/plan` → `.claude/plans/<task>.md`) → `/clear` → execute loading only the plan (`/execute`). Intermediate planning attempts don't carry into execution context. Command templates: snippets/command-{prime,plan,execute}.md → copy into project `.claude/commands/`.

## 4. Tool output

PHP projects → skill `pao` (bucket: php): tests/static-analysis compressed to ~20 tokens JSON.

## 5. MCP & model audit

- MCP server unused 7 days → disable (its schema loads in every request). CLI instead of MCP where possible.
- Selection (what to connect, not what to trim) at onboarding/sync — skill `mcp-advisor` (the reverse axis of this audit).
- Model — natively via `/model`: routine/recon → Haiku, architecture/debug → senior model. Don't use third-party proxy routers (claude-code-router): extra risk layer without native guarantees. Or via the `tasks` plugin: `/task:design|dev|review|research|fix` pins model+effort per intent (no manual switching).

## 6. Platform layer — via `gh`, not files

GitHub ops (PRs, review threads, others' code, releases) → **GitHub CLI**, not whole-file reads, not web UI. NOT a `git` replacement: local VCS (commit, branch, working-tree diff, log, rebase) stays with `git`, `gh` can't do it. Split: local → `git`; platform → `gh`. `gh` returns minimal slice: `gh pr diff <N>` — diff only; `gh pr view <N> --comments` — threads only; `gh api .../contents/{path}` — one file (not a repo clone). Full algorithm/commands → skill `oss-dev/gh-review`.

## 7. Hard noise block — permissions.deny

`permissions.deny: Read(...)` in `.claude/settings.json` — hardware read block (`storage/logs/**`, `node_modules/**`, `*.lock`). Presets: `configs/claude-code/` + `scripts/apply-permissions.sh`. In Laravel+Boost projects do NOT block `vendor/**` — guidelines live in `vendor/laravel/boost/.ai/`.

## 8. Subagent recon, selective input, cost visibility

- **Recon via subagents.** Read-heavy investigation (route map, DB/migration impact, large-file exploration) → a read-only subagent: it reads in its OWN context window and only a summary returns, so the main context stays lean (subagent reads don't count against it). Officially recommended for token economy. NOT parallel agent-teams for cost (those ~7× tokens).
- **Selective input (5–50× saving).** Don't `Read` a whole file — a slice (function/class/route ±context); logs → `tail -n`/`grep` by identifier, not the whole file; errors → last stack-frame; PR/diff → `git diff`/`gh pr diff`. "Point, don't paste." Caveat: a `deny` rule on a path also blocks `cat/head/tail/sed` on it — don't deny dirs you legitimately need to tail.
- **Cost visibility.** `/usage` after long recon → if bloated: handoff (`.claude/handoffs/…`) + `/clear`, or `/compact "focus on modified files + test commands"`. (Command is `/usage`, not `/cost`.)
- **Code navigation — graph/symbols, not grep.** Before broad multi-file search — structure first: `serena` (`get_symbols_overview`/`find_symbol`) or cross-project `maind graph` (node neighbors), instead of whole-file reads. Details + graph-staleness caveat → skill `ecosystem-mcp` (maind).
- **Multi-agent fan-out (Workflow) is costly — tier it, don't poll.** Subagent fan-out ≈15× chat tokens: justified on research/design (breadth-first), not on coding. In the script: (1) **set model/effort per RISK on EVERY `agent()`** — cheap models execute, the expensive one designs/arbitrates; inheriting the parent model is a deliberate exception, not the default (else the whole fleet runs on the top model); (2) **don't poll background agents** — the completion notification arrives on its own; `ScheduleWakeup`/poll loops burn the cache; (3) **keep prompts deterministic** (no timestamp/random, override via a separate param) so `resumeFromRunId` returns finished stages free from cache; (4) **log `budget.remaining()` after each wave** or you learn of the overrun only afterwards. Reference impl — harness agent-packs `dev-loop.js`.

## 9. Ladder — measured magnitudes (what saves most)

Prioritize by ROI (verified 2026-07):

- Empty session already carries ~20–30k hidden tokens (system prompt + tool schemas + CLAUDE.md + skills meta) — measure with `/usage`/`/context` FIRST.
- CLAUDE.md trim — up to ~92% of injected context; highest per-repo ROI (resident every turn).
- MCP ~10–20k tokens/server resident — keep 1–2 per task, CLI otherwise; lazy tool-search cuts schemas ~84%.
- Model routing up to ~75%: Haiku (recon/routine) · Sonnet (default) · Opus (architecture/hard debug). Via `/model` or `tasks`.
- Prompt caching 0.1× on the cached prefix (system/CLAUDE.md/tool schemas); pays off on repeat turns.
- English ≈ 2–3× denser than Russian in tokens: keep static wiring (agents/rules/policies) in EN, human-facing in Russian (~60–67% saving on translated content).
- Output tokens bill at output rate (~4–5× input) — cut verbose output: skill `compact-responses`; "lean" output-style via harness (session-wide, ~65% for caveman-ultra-class styles).

Numbers are "up to, workload-dependent," not SLAs; they compound.

## Quality checklist

- [ ] CLAUDE.md ≤200 lines, each line passes "would it surprise an experienced dev"
- [ ] Read-heavy recon delegated to subagents; files/logs read as slices (tail/rg/diff), not whole
- [ ] Topic/local rules moved to `.claude/rules/` with `paths:`
- [ ] deny noise preset applied; `vendor/**` not blocked in Boost projects
- [ ] prime/plan/execute commands copied into `.claude/commands/`
- [ ] MCP servers checked within last 30 days
- [ ] Platform ops (PR/review/others' code) go via `gh`, not whole-file loading

## References

- snippets/rules-example.md, snippets/claude-md-checklist.md
- snippets/command-prime.md, snippets/command-plan.md, snippets/command-execute.md
- https://code.claude.com/docs/en/memory — CLAUDE.md, rules, auto memory
- Skill `pao` (php) — compress PHP tool output
- Skill `oss-dev/gh-review` — gh-driven review/handoff (section 6, full algorithm)
- Skill `compact-responses` (general) — terse model output (different economy axis: the answer, not the context)
- Skill `task-brief-template` (general) — Plan→Clear→Execute pattern and `/plan`, `/execute` command templates
- Skill `session-handoff` (general) — compress session into a handoff before `/clear`
- Skill `mcp-advisor` (general) — pick MCP for a project at onboarding (the reverse side of the audit: what to connect)
- Skill `ecosystem-mcp` (maind) — project memory/graph MCP tools: when to call graph/Serena instead of file search

<!-- ru-source-sha256: e671b5d90b8e2bdcfe31c70c8de933915f8c8adbf40cfafb815e367a65e4223d -->
