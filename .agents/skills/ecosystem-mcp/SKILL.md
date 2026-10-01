---
name: ecosystem-mcp
description: "Use to recall ecosystem decisions, choose memory/code-graph MCP tools, or diagnose missing project connections."
---

# Ecosystem academici — MCP-tools for this project

This project (`azguard`) — ecosystem node The **academici**, connected to the hub **mAInd**. Via
hub undergoes two independent binding mechanisms; both are rolled out here like MCP-servers (see
`.mcp.json`). This file is managed from maind (`templates/skills/ecosystem-mcp/SKILL.md`) — correct
there and `maind sync`, and not in the copy of the project.

## Tools and when to call them

| MCP-server | What is this | When to call |
|:--|:--|:--|
| **agentmemory** | Explicit long-term memory. Private scope project - `project:azguard`; general scope is set separately | Call when past decision or handoff may change operation. For continuity write one `active-handoff`; progress stored in the repository |
| **serena** (code graph) | Graph **code inside** project: symbols, links, implementations, file overview (engine Serena) | For **code navigation** — `find_symbol`, `find_referencing_symbols`, `get_symbols_overview` instead of reading entire files (saves tokens). Available if `.mcp.json` there is a code server (usually `serena`); no - navigate with regular search. Not to be confused with cross-project maind-graph |
| **maind** | The hub itself (topology **between** projects, connection status) | When you need project connections to other ecosystem nodes or a status summary |

Normal recall remains private scope project. Graph namespaces do not automatically
memory access: shared `ns:<name>` is read and written only when explicitly scope and allowed
`memory_namespaces`. Do not duplicate into memory what is already in the code, plan or git.

**Autoload is disabled by default (`MODE=manual`).** mAInd remains available via MCP,
and memory rises upon request. `MODE=maind` with SessionStart digest is enabled only after
measured evidence of benefit.

## Cross-project task (2+ project)

The task concerns several ecosystem projects at once (for example «analyze X and Y and improve
common between them») — raise the cross-project context explicitly, in three steps:

1. **Connections** — what projects are generally related: `maind graph --project azguard` (CLI) or
   `maind_graph_path`/`maind_graph_neighbors` (MCP) — path/neighbors between graph nodes.
2. **Group context** — raise shared memory namespace explicit (it is not in the digest by default):
   `maind memory recall --scope ns:<name>` (CLI) or `maind_memory_recall` c with this scope (MCP).
3. **The output is written to the general scope**, not in a private project - otherwise the next project of the group will be his
   will not see: `maind memory remember --scope ns:<name> "<fact>"` (CLI, `--scope` = direct address
   bucket - not to be confused with `--project --share`, which has a different purpose: private + group
   at once) or `maind_memory_store` with `scope: "ns:<name>"` (MCP).

## Rule: graph first/characters, then broad search

Before wide `grep`/reading many files - first ask for the structure, it's cheaper in terms of tokens:

- **code navigation** → serena: `get_symbols_overview` (file overview), `find_symbol` (body by name),
  `find_referencing_symbols` (who refers) — instead of reading entire files;
- **connections between projects** → `maind graph query`/`neighbors` — neighbors of a node instead grep by manifests.

**Disclaimer:** maind-the graph is rebuilt MANUALLY (watcher'but no) — may be out of date. If the result
looks incomplete/obsolete → `maind sync --only graph`, then repeat; or a fallback to a regular search.
serena-code graph (LSP) is relevant on the fly - the disclaimer does not apply to it.

## Project Communications

- **scope memory:** `project:azguard`; ecosystem facts - in scope `global`.
- **namespaces:** `axioma` (within a group - general, between groups - isolated).
- **linked to:** —.
- see current connections: `maind graph --project azguard`.

If something breaks in a linked project, remember that the link exists: changes here can
influence neighbors namespace, and the user should be warned about this.

## If the connection is broken

The tools above only work when the connection to the hub is up. If MCP-memory calls/column not
respond or memory is empty:

1. Check: `maind health --cwd` (read-only) or `maind health --cwd --deep` with a real symptom;
   `maind verify` checks if memory is full/graph across the entire ecosystem or everything is raised
   «idle». Memory through the eyes - viewer `http://127.0.0.1:3113`.
2. When status `partial` / `standalone` — fix: `maind health --cwd --fix` (will raise the demon
   memory and add MCP-hub servers into the project).
3. **Notify user**: status not yet `connected`, changes to this session **will not hit** in
   shared memory and graph - an irreparable gap will appear in the history of the ecosystem.
