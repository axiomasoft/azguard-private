---
name: agent-packs
bucket: system
version: 0.1.0
description: "Install and VERSIONED upgrade of agent-packs (Claude Code agents/workflows/hooks) from harness into a consumer project: stack detect -> interview -> render+copy -> lock; upgrade via CHANGELOG delta with 3-way reasoning over sha256 in lock, no diff engine. Activate on: install/upgrade agent-pack, add roles+dev-loop+review-gate to project, versioned upgrade of agent templates, agent-pack.lock."
risk: write
persona: architect
tags: [agent-packs, templates, versioning, upgrade, install, claude-code, harness, lock]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude]
sha256: ""
---

# Agent Packs — install & versioned upgrade

**Pack** = versioned bundle of Claude Code artifacts (agents `.claude/agents/`, workflows `.claude/workflows/`, hooks, `settings` fragment, guideline docs) living in `harness/configs/claude-code/agent-packs/<pack>/`, installed by the agent into a consumer project. DATA is distributed (`pack.json` + `CHANGELOG.md` + templates); agent does install/adapt/upgrade from metadata — NO Python/Mustache generator engine. Project stores install state in `.claude/agent-pack.lock.json` (committed, like `composer.lock`).

## Activate when
- "Install agent-pack / add roles + dev-loop + review-gate to project."
- "Upgrade agent-pack" (new pack version in harness).
- Need versioned set of agents/workflows/hooks instead of manual copy.

**Anti-trigger:** editing ONE agent/workflow for a specific task — edit the file directly, don't touch the pack (packs = versioned SET, not one-off edits).

## Manifest (`pack.json`)

```jsonc
{
  "schema": 1, "pack": "<name>", "version": "0.1.0", "description": "...",
  "stack": { "shape": "backend|frontend|fullstack", "framework": "...",
             "detect": { "all_of": ["artisan", "composer.json"] } },
  "requires": { "skills": ["..."], "harness_presets": ["base", "laravel"] },
  "interview": [ { "id": "PACK_PREFIX", "q": "...", "default": "{project_dirname}",
                   "kind": "slug|bool|enum", "detect": "<shell|file-hint>" } ],
  "files": [ { "src": "agents/reviewer.md",
               "target": ".claude/agents/{PACK_PREFIX}-reviewer.md",
               "kind": "template|verbatim|merge", "role": "agent",
               "user_owned": false } ]
}
```

`kind`:
- **template** — substitute `{{PLACEHOLDER}}` from answers + resolve `<!-- pack:if KEY -->...<!-- pack:endif -->` blocks by bool answer, write.
- **verbatim** — copy unchanged (+ chmod if set).
- **merge** — JSON-merge into existing project file, append-if-absent.
- `user_owned: true` — user edits this file (e.g. `.env.ini`): NEVER silently overwrite on upgrade.

## Delegate to deterministic CLI (preferred)

If `harness agent-packs` is available (resolve launcher as in orchestrate/SIBLINGS), CALL it for install/upgrade — deterministic stdlib render: resolves interview from `detect:` probes+defaults WITHOUT the agent, substitutes placeholders, writes lock in the same schema below. Commands:

```bash
harness agent-packs render  --auto --pack <pack> --target . [--answer KEY=VAL ...]
harness agent-packs upgrade        --pack <pack> --target .
harness agent-packs doctor  [--pack <pack>]
```

Agent-driven render (below) = FALLBACK only when CLI returns `needs-agent`/`assumed`/`conflict` (ambiguous enum without detect, locally-edited files on upgrade) or when prose adaptation for non-standard infra is needed. Both paths write ONE lock format (`base_sha256` = sha of rendered, `atomic_write_json`), so they're compatible: agent finishes what CLI flagged unresolved/conflicting, and vice versa.

## Install algorithm (agent-driven fallback)

1. **Detect stack.** Run `stack.detect` (e.g. `test -f artisan && test -f composer.json`); pick matching pack(s). Prefill defaults from detect.
2. **Interview — project-only.** Ask only `interview[]` not resolved by detect; show default, allow accept. (Ambiguous `TEST_FRAMEWORK` — ask explicitly: a wrong guess silently corrupts agent output.)
3. **Preconditions.** Check `requires.harness_presets` (suggest `harness permissions --target . --preset ...`) and `requires.skills` (via `skm:skills`/profile). Warn, no hard-fail.
4. **Render + copy.** For each `files[]`: substitute `{PLACEHOLDER}` in `target` and (for `template`) in content; resolve `pack:if` blocks; write. `verbatim` — copy + chmod. `merge` — JSON-merge fragment append-if-absent (backup `.bak`, don't clobber foreign hooks).
5. **Lock.** Create `.claude/agent-pack.lock.json` (schema below): version, answers, `files[]` with `base_sha256` of RENDERED content.
6. **Post-check.** Run checklist `docs/skiller/agent-framework/post-integration-checklist.md`. Validate workflows by brace balance/non-empty (top-level return/await — do NOT use `node --check`).

## Lock (`.claude/agent-pack.lock.json`)

```jsonc
{ "schema": 1, "packs": {
    "<pack>": {
      "version": "0.1.0",
      "source": "harness:configs/claude-code/agent-packs/<pack>",
      "answers": { "PACK_PREFIX": "...", "PHP_RUNNER": "...", "...": "..." },
      "files": [ { "src": "agents/reviewer.md",
                   "target": ".claude/agents/<prefix>-reviewer.md",
                   "kind": "template",
                   "base_sha256": "<sha256 of RENDERED at install>",
                   "local_modified": false } ],
      "removed_optional": [] } } }
```

Three fields enable upgrade with NO diff engine: **version** (delta source), **answers** (deterministic re-render of new version), **files[].base_sha256** (hash of what we wrote → divergence = one hash compare).

## Upgrade algorithm (core)

1. **Delta.** From `CHANGELOG.md` collect versions `(V_old, V_new]`: their "Migration notes" + per-file changes.
2. **Re-interview new only.** If notes declare a new `interview` answer — ask only it; else use `lock.answers`.
3. **3-way per file (no diff engine):**
   - `local_modified` = `sha256(file_on_disk) != lock.base_sha256` (locally edited).
   - `base_changed` = `sha256(re-render V_new with answers) != lock.base_sha256` (upstream changed).
4. **Categories:**
   - **safe-apply** (`base_changed && !local_modified`) → overwrite with re-render.
   - **conflict** (`base_changed && local_modified`) → do NOT overwrite; show CHANGELOG delta; optionally drop `<target>.pack-new` for manual merge.
   - **new-optional** (new file, marked OPTIONAL in CHANGELOG) → offer opt-in.
   - **removed** (was in lock, absent in `V_new.files`) → do NOT auto-delete; ask.
   - **user_owned** — even on `base_changed` don't overwrite; only mention new default.
5. **Plan → confirm → apply.** Show categorized plan, await consent, apply (backup `.bak`), then **bump lock**: `version=V_new`, recompute all `base_sha256`, update `local_modified` and `answers`. Leave conflict files with `local_modified:true` and a deferred-upgrade mark.

## Conflict safety
- settings — merge-only append-if-absent (event,matcher,command); don't duplicate a hook.
- Boost project — do NOT touch generated CLAUDE.md/AGENTS.md (all in `.ai/guidelines/` + `boost:update`).
- Agent names under `{PACK_PREFIX}` — check collisions with existing and with plugin agents.
- Multiple packs — detect path collisions (shared verbatim with identical content → skip).
- Never auto-delete project files or overwrite locally-edited ones.

## Anti-patterns
- Engine generation (Mustache/Python) instead of agent-driven render.
- Auto-overwrite of locally-edited file or auto-delete of removed file.
- Re-interviewing answers unchanged between versions.
- Per-template versions (version is per-PACK).
- `node --check` on dev-loop workflow (top-level return/await — false fail).

## Quality checklist
- [ ] Stack detected; interview only on project-specific (not auto-resolved).
- [ ] All `template` files rendered (no leftover `{{...}}`); `verbatim` copied + chmod.
- [ ] `merge` applied append-if-absent with `.bak`; foreign hooks intact.
- [ ] Lock written/updated; `base_sha256` = hash of rendered; `answers` complete.
- [ ] Upgrade: conflict files shown, NOT overwritten; removed not auto-deleted.
- [ ] Post-check passed (post-integration-checklist).

## Links
- `harness/configs/claude-code/agent-packs/` — pack bases (source of truth).
- `docs/skiller/agent-framework/` — framework model; `post-integration-checklist` — post-check.
- `laravel-architecture/laravel-subagents` — orchestration protocol the pack roles implement.
- `general/context-economy`, `general/session-handoff` — main-session context on delegation.

<!-- ru-source-sha256: c82c16069c361b8f89f5f5e6909ed7ac71242f73ae70b075a417c2ea6e90c1eb -->
