---
name: shared-memory
bucket: system
version: 0.3.0
description: "Constellation shared brain via hook ~/.claude/hooks/memory/ + mAInd daemon. Recall before work, remember after. Project canon = MODE=maind (daemon :4111, scope isolation maind-side): private project:<id> + tag-shared ns:<namespace> + global; recall unions them; SessionStart auto-injects digest (native memory off). Routing rule: one fact in one store. Swappable backend: maind|native|file|federation|off."
risk: write
persona: architect
tags: [memory, cross-project, topology, recall, context, coordination]
requires: [local-topology]
produces_for: []
outputs: []
snippets: []
adapters: [claude]
sha256: ""
---

# Shared Memory (constellation shared brain)

Hook `~/.claude/hooks/memory/` = shared brain for one group (`brain`) only. Structure like `auto-approve`: switch `memory.sh`, mode in `env.ini` (`MODE`), groups/members in `config.json`, backends in `modes/`. Member resolution via topology (`local-topology`).

## When to use

- **Recall before work** — at start of a substantive task:
  ```bash
  ~/.claude/hooks/memory/memory.sh recall "<task topic: domain, pattern, bug>"
  ```
- **Remember after** — record a cross-project-useful fact (decision, divergence, shared-package candidate):
  ```bash
  ~/.claude/hooks/memory/memory.sh remember --type project "<fact in one phrase + why>"
  ```
- **Inspect:** `memory.sh status` (mode, group, members, fact count), `memory.sh members` (who's in group).

Only what's useful to **another** project in the group (dups, divergent impls, shared decisions, shared-package candidates). Local/one-off → skip.

## Modes (backends) — `env.ini` MODE

- **maind** ← **canon for application projects.** Delegates to `maind memory` (daemon :4111). Same memory as MCP `maind_memory_*`: private `project:<id>` + tag-shared `ns:<namespace>` + `global`, recall unions them. **Isolation is maind-side** (single daemon pool, ignores `namespace`; maind embeds scope-marker in content and filters on read). No brain needed — scope from project. `maind onboard` sets `MODE=maind` and namespaces (shared `axioma` + own, see [namespaces]).
- **file** — one shared store per group (markdown).
- **federation** — file-based: per-member `memory/` + shared store. Git-transparent, no daemon. For **infra nodes** (brain/swissknifeman) only; NOT the same as maind graph-federation.
- **agentmemory** — old direct daemon proxy (bypassed API/scope) — **do not use**, replaced by `maind`.
- **off** — disabled.

> ⚠️ Write/read project memory only via `maind memory` (hook in `MODE=maind`) or MCP `maind_memory_*` — bypass writes (raw agentmemory) lack the scope-marker and aren't found by scoped-recall. Isolation details: `docs/maind/guide/memory.md`.

## Groups & members (membership)

- **MODE=maind (canon):** membership via project **namespaces** in `config.json` (`projects[].namespaces`), not via brains. Shared home = `axioma` (all own projects); project adds own/product namespace (memster → `[axioma, memster]`); external clients → namespace without `axioma`. Visibility = `project:<id>` + its `ns:<…>` + `global`.
- **MODE=file/federation (infra):** `brains` → `{ name: { members, store, agentmemory } }`; member sees a brain only if in its `members`.

## Per-project override (like auto-approve)

Global: `~/.claude/hooks/memory/env.ini` (MODE) + `config.json`. In project: `<project>/.claude/memory.env.ini` (MODE) and `<project>/.claude/memory.config.json` (group/members); plus `memory_brain` in `<project>/.swissknife.json` — project's default group.

## SessionStart auto-load (digest) & native-vs-maind routing

`MODE=maind` installs a SessionStart hook injecting project memory **digest** into context (`maind memory digest` → `additionalContext`) and disables native auto-memory (`autoMemoryEnabled:false`). Per-project: `maind onboard <id> --memory maind|native|off` (default `maind`).

**Routing rule — one fact in one store, no dups:**
- **maind** (`maind memory` / `maind_memory_*`) — project + home memory (private `project:<id>`, shared `--share`, cross-project/cross-agent, start digest). ← default.
- **native MEMORY.md** — off when `MODE=maind`. If `--memory native`: only small always-handy invariants, **no copies** of maind facts.
- **file-federation (`MODE=file/federation`)** — legacy, infra nodes only (brain/swissknifeman).

## Discipline

- Recall **before**, remember **after** — cross-project-useful only.
- Fact = one phrase + **why**.
- **Don't duplicate** a fact across native and maind — one store per routing rule above.
- Hook only appends; never touches others' facts. No secrets in memory.

## Quality checklist

- [ ] `recall` on topic done before substantive task.
- [ ] Only cross-project-useful fact recorded (not local trivia), with "why".
- [ ] Fact in correct scope (private by default; `--share`/shared only if useful to home).
- [ ] Project uses `MODE=maind` (daemon); `maind health` round-trip passes.

## Links

- `~/.claude/hooks/memory/` — the hook (`memory.sh`, `env.ini`, `config.json`, `modes/`, `CREDITS.md`).
- `local-topology` (system bucket) — member resolution via `~/.swissknifeman/topology.json`.
- `cross-project-coordinator` (system bucket) — source of cross-project findings (dups/divergences/shared-package candidates) worth `remember` for the group.
- `general/session-handoff` — adjacent: one-off context handoff between sessions, vs shared-memory = persistent shared group store.
- Install hook files: `harness hooks --global` (harness package).

<!-- ru-source-sha256: 5624f36811f16981ae52f710294f3351a32f2ed48b2dcacc944bf4fd4dcb72c3 -->
