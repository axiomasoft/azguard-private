---
name: local-topology
bucket: system
version: 0.1.0
description: Root map of the local dev environment — three hub nodes (Brain vault, swissknifeman, projects base) and navigation between projects, their code and docs. Read from ~/.swissknifeman/topology.json
risk: read
persona: architect
tags: [topology, architecture, navigation, meta, cross-project]
requires: []
produces_for: [cross-project-coordinator, shared-memory, brain-locator, swissknifeman-locator]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Local Topology

Use when: locate **where** nodes live, reach a **neighboring project** (code or docs), or explain the overall scheme. Root navigation entry point for any agent in any connected project.

## Three hub nodes

Linked along two lines (docs and skills/hooks):

| Node | Role | Provides |
|:---|:---|:---|
| **Brain vault** (`docs-hub`) | Single docs point | Obsidian vault; CLI `brain` + `docs-watcher` sync `docs/`/`docs-public` between vault and repos both ways (git-merge) |
| **swissknifeman** (`skills-hub`) | Single skills/hooks point | Skill registry; distributes to projects (marketplace/vendor), carries global hooks and permission presets |
| **Projects base** (`workspace`) | Where code lives | Directory with all project repos (arbitrary structure) |

- Nodes don't know each other directly — topology config (below) links them.
- Brain finds projects via `repo:` frontmatter in its notes; swissknifeman via `~/.swissknifeman/projects.json`.

## Node resolution: `~/.swissknifeman/topology.json`

Sole source of truth for node locations. Schema:

```json
{
  "version": 1,
  "nodes": {
    "brain":         { "path": "/home/<user>/Vaults/Brain",            "role": "docs-hub" },
    "swissknifeman": { "path": "/home/<user>/projects/packages/swissknifeman", "role": "skills-hub" },
    "projects_base": { "path": "/home/<user>/projects",                "role": "workspace" }
  },
  "created_at": "ISO8601",
  "updated_at": "ISO8601"
}
```

Read paths (`jq`):

```bash
# all nodes
jq -r '.nodes | to_entries[] | "\(.key)\t\(.value.path)\t(\(.value.role))"' \
  ~/.swissknifeman/topology.json

# single node
jq -r '.nodes.brain.path'         ~/.swissknifeman/topology.json
jq -r '.nodes.projects_base.path' ~/.swissknifeman/topology.json
```

Or via CLI:

```bash
skiller topology show
skiller topology show --json
```

**No file** → topology not set up on this machine. Create interactively (asks paths to Brain vault, swissknifeman, projects base; auto-detects defaults):

```bash
skiller topology init
```

Or reach a specific node **without** the map: per-node locators resolve their node via `maind`/`$HOME` even when `topology.json` is absent — `brain-locator` (node `docs-hub`) and `swissknifeman-locator` (node `skills-hub`).

## Navigation

**Neighboring project (code).** `projects_base` → project dir. Projects known to swissknifeman:

```bash
jq -r '.projects[].path' ~/.swissknifeman/projects.json   # connected to registry
ls "$(jq -r '.nodes.projects_base.path' ~/.swissknifeman/topology.json)"
```

**Any project's docs.** Brain is the single point. Docs live both in repo (`<repo>/docs`, `<repo>/docs-public`) and mirrored in vault; `brain` keeps them in sync:

```bash
brain list                  # all vault projects and their repo links
brain status <project>      # docs ↔ repo divergence?
brain sync <project>        # sync (two-way git-merge)
```

**Skills/hooks/configs.** swissknifeman node:

```bash
skiller list          # which projects connected to what
skiller status        # current project state
# skills — skiller package (skills/<bucket>/); hooks and presets — harness package
```

Connect a new project to skill distribution: `skiller connect` (Claude Code, plugin marketplace) or `skiller vendor` (Cursor etc.).

## Principles

- **Map is read-only.** This skill explains the scheme and reads `topology.json`; it does **not** mutate nodes or rewrite config. Create/edit config only via `skiller topology init`.
- **Paths are machine-specific.** `topology.json` lives in `$HOME`, not in repo; per-machine (like Brain's `.projects.json`). Don't commit it into projects.
- **Nodes are autonomous.** Brain and swissknifeman work without the map; map just gives agents a shared point to see the scheme and hop between nodes.

## Checklist

- [ ] Read `~/.swissknifeman/topology.json` (or suggested `topology init` if absent)
- [ ] Used real node paths, not hardcoded
- [ ] For docs — went through Brain (`brain status/sync`), didn't edit mirrors by hand
- [ ] Didn't commit `topology.json` into project

## Links

- `cross-project-coordinator` (system bucket) — traversal of linked projects on this map (read-only duplicate/divergence analysis). Already implemented on top of this map; further plans — `docs/roadmap.md`.
- `shared-memory` (system bucket) — shared group "brain"; resolves members via the same topology.
- `brain-locator` (system bucket) — drill-down into node `docs-hub` (Brain vault) with map-less fallback resolution.
- `swissknifeman-locator` (system bucket) — drill-down into node `skills-hub` (swissknifeman) with map-less fallback resolution.

<!-- ru-source-sha256: 9cec59ba30369843ebffa6f7304dfbd00fe6449702f0ef455f7ca494b41781f7 -->
