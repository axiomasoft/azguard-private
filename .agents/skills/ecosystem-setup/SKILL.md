---
name: ecosystem-setup
description: "Use when changing project skills, plugins, memory scopes, roles, or environment connections in this ecosystem."
---

# Project setup `azguard` (node academici)

This project is an ecosystem node **academici**, connected to the hub **mAInd**. File is managed
from maind (`templates/skills/ecosystem-setup/SKILL.md`) — edit there and `maind sync`, not in copy.

**Project status:** type `php-package` · scope `project:azguard` · namespaces `axioma`.

## General rules (do not violate)

1. **Optimal, not maximum.** Select a set of skills/plugins for the stack and project role.
   Don't include other people's roles (`php`/`python`/`mobile` on the front, front on backend-package) — is noise.
2. **Not obvious → ask.** If the optimal set is unclear (hybrid stack, controversial buckets) —
   ask user (2-3 curated option), do not guess and do not bet «just in case».
3. **Skill channel always ask** (connect vs vendor) — it changes what is committed to the repo.

## Decision tree (question → options → action)

**Project type** (`php-package`) sets the basic skill profile. If type `standalone` —
he **not recognized**: don't put everything, select the profile explicitly (`node-frontend`, `laravel-project`,
`php-package`, `python-backend`, …) or ask.

| Solution | Options | Action |
|:--|:--|:--|
| **Memory** | maind / native / off | `maind onboard azguard --memory <…>` (default maind) |
| **Namespaces** | general (group) + personal (insulation) + `*` (infra) | `--namespace a,b`; inside ns memory is shared, between - isolated |
| **Skill channel** | connect (bucket, not in repo) / vendor (skills in repo + lock) | ask; `skiller connect` or `.swissknifeman/config.json` + `skiller sync` |
| **Role buckets** | by type profile; CORE always | not «everything in a row»; not obvious - ask |
| **Environment** | permissions / MCP / auto-approve / brain | is delegated to `harness` |
| **Code graph** | Serena on/off | `--with-code` |

## Frequent tasks

- **Add role/skills** → ask channel; connect: `skiller connect --plugins <current>,<new>`;
  vendor: add in `.swissknifeman/config.json` → `skiller sync`.
- **Change plugin set** → edit `enabledPlugins` in `.claude/settings.local.json`
  (leave the relevant ones to the stack), or `skiller connect --plugins …` (overwrites).
- **Change memory/namespaces** → `maind onboard azguard --memory … --namespace …`.
- **Reroll artifacts** → `maind sync --project azguard` (or `--only mcp,graph,…`).
- **Check connection** → `maind health` (round-trip-gate), `skiller status`, `maind doctor`.
- **Check always-on budget** (before adding the skill/agent/CLAUDE.md-block/MCP-server) →
  `skiller budget --first-turn` (per-project, target ≤7K/warn >10K); fleet - `maind doctor --context`;
  trend by session - `analyst sessions`.

The complete tree of all layers is in the dock maind «Decision Tree: Project Setup»
(`packages/maind/docs/guide/onboarding-decision-tree.md`).

## Project Communications

- namespaces: `axioma` · scope: `project:azguard` · linked to: —.
- current connections: `maind graph --project azguard`.
