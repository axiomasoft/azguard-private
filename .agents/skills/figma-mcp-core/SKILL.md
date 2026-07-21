---
name: figma-mcp-core
bucket: architect
version: 0.1.0
description: "Figma Dev Mode MCP discipline: real tools (get_metadata, get_variable_defs, get_code, get_code_connect_map, get_screenshot, search_design_system), no reading values off screenshots, selection-driven algorithm, W3C tokens.json extraction, Code Connect reuse. Activate for any UI-from-Figma task via connected MCP, before mapping to a framework."
risk: read
persona: architect
tags: [figma, mcp, design-system, design-tokens, dev-mode, code-connect, ui]
requires: []
produces_for: [figma-to-vue, figma-to-flutter]
outputs: ["docs/design/tokens.json", "docs/design/figma-audit.md"]
snippets: [tokens.json, design-system-checklist.md]
adapters: [claude, cursor, fable]
sha256: ""
---

# Skill: Figma Dev Mode MCP — structural discipline

Framework-agnostic base skill for the **official Figma Dev Mode MCP server**. Read data via MCP tools, do not guess from the picture. Framework mapping lives in derived skills `figma-to-vue` (Vue/Tailwind), `figma-to-flutter` (Flutter).

Activate when:
- Figma MCP is connected (Dev Mode desktop server or remote) and a screen/component must be implemented;
- extracting design tokens (colors, typography, spacing) from Figma to code;
- auditing a Figma file's design-system before development;
- agent starts guessing colors/sizes from a screenshot instead of reading variables.

Dev Mode MCP is **selection-driven and code-first**: it returns context for the *current selection in Figma desktop* or a node-id from a pasted link, NOT a tree walk over `fileKey` (that is REST API, not MCP). The algorithm is built from the selection.

## Real Figma Dev Mode MCP tools

| Tool | Returns | When |
|:--|:--|:--|
| `get_metadata` | sparse selection tree: layer id, name, type, position, size | step 1 — screen structure map, token-cheap |
| `get_variable_defs` | variables and styles (design tokens) used in selection | token extraction; before code generation |
| `get_code` (synonym `get_design_context`) | code for selection; defaults React+Tailwind, picks up project framework | as **draft reference** for structure/values, not final code |
| `get_code_connect_map` | Figma node id → Code Connect components in codebase | check if a component already exists for this node |
| `get_screenshot` | screenshot of selection | **only** visual verification of result; NOT a source of values |
| `search_design_system` (remote) | search components/variables in connected libraries | find canonical component/token instead of duplicating |

## NO SCREENSHOT FOR VALUES

`get_screenshot` allowed **only** for final visual "match/no-match" check. Extracting values from it is an antipattern: no exact tokens (colors/sizes rounded visually), no hierarchy or override chains, no auto-layout params. All values come from `get_variable_defs` and `get_metadata`.

## Algorithm

1. **Get selection.** Ask user to select the frame/component in Figma desktop (or give a link with node-id). Without selection MCP has no context.
2. **Structure map.** `get_metadata` → node tree (id, name, type, position, size). Identify root screen frame, child components, text nodes. Nodes with meaningful names (`card/surface`, not `Rectangle 42`) are component candidates.
3. **Reuse.** `get_code_connect_map` on selection → if node already links to a codebase component, **use the existing component**, do not regenerate. For library elements — `search_design_system`.
4. **Tokens.** `get_variable_defs` → all selection variables/styles. Structure into `tokens.json` per W3C Design Token Format (see `snippets/tokens.json`). If variables have Modes (light/dark) — record value per mode.
5. **Code draft.** `get_code` → read as reference for structure and token wiring, do NOT copy verbatim. Final code is written by the derived skill per project conventions (`figma-to-vue`/`figma-to-flutter`).
6. **Verify.** After generation — one `get_screenshot` for visual comparison. Fix discrepancies via data (steps 2–4), not by eyeballing.

## tokens.json format

W3C Design Token Format — single intermediate artifact for all platforms. Full example with color/spacing/typography/border-radius and multi-mode in `snippets/tokens.json`. Token name keeps Figma semantics (`figma:variable/Primary/Blue-500` in `$description`).

## Design-system audit

Before developing against an unfamiliar file — run the readiness checklist (`snippets/design-system-checklist.md`): all colors in Variables, typography only via styles, spacing multiple of base grid, interactive components have all Variants, dark mode as Variable Mode, etc.

## Figma antipatterns and agent actions

- **Color without variable** (`fills` with raw `color`, not bound to a Variable): create token `color/unnamed/hex-XXXXXX`, flag in audit "needs naming by designer".
- **Fixed sizes instead of Auto-layout** (`layoutMode: NONE`): record as constant, flag "not responsive", do NOT generate flex/Expanded.
- **Absolute positioning of children**: read x/y/w/h, map to Stack/absolute (see derived skills).
- **Image fills** (`type: IMAGE`): do NOT download for analysis; use ref as placeholder-asset.

## Outputs

- `docs/design/tokens.json` — extracted tokens (W3C).
- `docs/design/figma-audit.md` — design-system audit report (on file review).

## Quality checklist

- [ ] Structure via `get_metadata`, not guessed from picture.
- [ ] Tokens via `get_variable_defs`, not from `get_screenshot`.
- [ ] `get_code_connect_map` checked — existing components reused.
- [ ] `get_screenshot` used only for final verification.
- [ ] `tokens.json` valid per W3C format, token names semantic.
- [ ] Code mapping delegated to `figma-to-vue` / `figma-to-flutter`.

## Links

- snippets/tokens.json — W3C example with multi-mode.
- snippets/design-system-checklist.md — design-system readiness checklist.
- [Figma Dev Mode MCP — Tools and prompts](https://developers.figma.com/docs/figma-mcp-server/tools-and-prompts/)
- [Guide to the Figma MCP server](https://help.figma.com/hc/en-us/articles/32132100833559-Guide-to-the-Figma-MCP-server)
- [W3C Design Tokens Format](https://design-tokens.github.io/community-group/format/)
- Related skills: `figma-to-vue`, `figma-to-flutter`.

<!-- ru-source-sha256: c0d92e7973ca1373ac37f08ef9a909acb8cec0c909dfcb8bbcda91602203f2aa -->
