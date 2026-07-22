---
name: query-craft
bucket: research
version: 0.1.0
description: "How to FORMULATE a search/doc-engine query (Perplexity Web · WebSearch · context7): version/stack, timeframe, format, limit; pick engine+mode and sources. Activate BEFORE any external search, esp. a library/framework: ban bare 'Laravel how to X?'. Not when-to-verify (verify-claims) nor what-to-connect (mcp-advisor) — only HOW to ask."
risk: read
persona: oss-dev
tags: [research, perplexity, search, query, rag, context7, claude-code]
requires: []
produces_for: [verify-claims]
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Query-craft — how to formulate a search-engine query

This is HOW to ask, not "when" and not "what to connect".
- **When** to search/verify a fact → `general/verify-claims` (claim classification, engine ladder, citation).
- **What** to connect to a project (context7/perplexity/web as servers) → `general/mcp-advisor`.
- **This** skill: the query is already decided — **phrase it to get a precise answer on the first try**, not generic fluff.

Closes: vague queries — "Laravel how to do X?", "Filament, we need min version 4". The engine knows neither
version nor stack nor context → answers by the median (often a stale major). Cost of vagueness = a wrong
answer later relied on.

## Iron rule: NEVER send a bare library query without version and context

Before a query about a framework/library/tool (Laravel, Filament, Vue, Nuxt, Livewire, Tailwind, …) the
query MUST carry: exact name + **version** (or "min X" range), **stack/environment**, **concrete task**,
**timeframe** when freshness matters.

| ❌ Anti-pattern (do NOT ask this) | ✅ Detailed query |
|:--|:--|
| `Laravel how to do queues?` | `Laravel 11: configuring Redis queues with horizontal scaling via Horizon — current config syntax as of 2025` |
| `Filament — minimum version 4` | `Filament v4 (min 4.x, not 3.x): declaring a custom form field — API changes vs v3` |
| `Vue composition api best practices` | `Vue 3.5 <script setup>: patterns for organizing composables in a large SPA, current recommendations` |
| `how to test` | `Laravel 11 + Pest 3: DB test isolation via RefreshDatabase vs transactions — current recommendation` |

> Version unknown → take the **latest stable** and **say so explicitly** ("current stable as of 2025",
> "latest major"), or first read the version from the project's `composer.json`/`package.json` (repo-grounded —
> read, not guessed). "Latest fresh" is the default when version isn't extractable from the project.

## Anatomy of a good query (5 slots)

Build from slots — a missing slot = lost precision. (Rules sourced from Perplexity's official prompt guide,
see provenance; same principles apply to WebSearch.)

1. **Verb instruction** — what to do: `compare` / `explain` / `list` / `show the syntax` / `find API changes`. The verb sets the answer shape.
2. **Subject + version** — exact name and **version/range**: `Filament v4`, `Laravel 11`, `Vue 3.5`, `min Filament 4 (not 3.x)`.
3. **Context/environment** — stack, goal, audience, constraints: `in a Laravel+Inertia+Vue project`, `for production`, `for a large SPA`, `compatible with PHP 8.3`.
4. **Specifics** — keywords, method/package/standard names, dates: `via Horizon`, `RefreshDatabase`, `Spatie Permission`, `as of 2025`.
5. **Format and limit** — how to present and how many: `as a table`, `top-5 list`, `with code examples`, `official docs only`. **A list with no limit → always give a number** (`top-5`).

Minimal template:
```
<verb> <subject vX.Y> for <context/stack>, <specifics/keywords>, <timeframe>.
Answer: <format>, <limit>.
```

## Slot 6 (constant): output contract for the AI consumer

The answer's consumer is an **AI agent, not a human**. Append a contract tail to every query
(unless the answer is deliberately meant for a human):

> _Answer for an AI agent: dense fact bullets, no intro/conclusion/advice; every fact cites a
> source (URL) and date/version; exact identifiers (package, version, method); if data isn't
> reliable, write unknown, don't guess; max N bullets._

For `search_deep` — same contract plus explicit section structure in the answer (the engine must
return a structured synthesis, not prose over 20 sources).

Why: human-facing answers (preambles, advice, prose) burn downstream tokens when an agent parses
them — Perplexity's own docs recommend structured output for machine consumption.

## Engine selection (what goes where)

Engine first — phrasing adapts to it. (The "when" ladder is in `verify-claims`; here — how to phrase for the
already-chosen engine.)

| Fact about… | Engine | How to phrase |
|:--|:--|:--|
| API/method/option of a specific library | **context7** (`resolve-library-id` → `query-docs`) | resolve the library id **with version** first; query narrow — symbol/option name, not a general question |
| best practice / "still current" / comparison / recent change / market | **Perplexity** (`search` / `search_advanced` / `search_deep`) | full 5-slot query (above); version+context MANDATORY |
| one known page/URL, trivial check | **WebSearch / WebFetch** | exact keywords; for WebFetch — a concrete question against the page |

> **context7 — version lives in the id.** context7's bottleneck isn't prose phrasing but the **correct
> id+version** at `resolve-library-id`. Resolve → confirm the right major was picked → then ask a specific
> `query-docs` (method/option name), not a general question.

> **Token-economy — push `perplexity-web` aggressively.** The bridge is flat-rate (browser profile/subscription,
> spends no API tokens) and returns a **compact synthesis**, not raw content into context. Offload retrieval+synthesis
> onto it **to the max**; WebSearch/WebFetch = known-URL/fallback only (pull raw → token-heavier). Engine priority
> (context7 API → aggressively perplexity-web → web fallback) lives in `verify-claims`.

## Perplexity Web MCP modes (three tools)

Tools of the `perplexity-web` server (keyless bridge). Mode choice = depth/speed balance:

| Tool | When | Cost | How to phrase |
|:--|:--|:--|:--|
| `mcp__perplexity-web__search` | default: one verifiable fact, fast cited answer | fast | one focused 5-slot query |
| `mcp__perplexity-web__search_advanced` (`sources: web\|academic\|social`) | when **source control** matters: `academic` for papers/research, `social` (Reddit/forums) for lived experience/edge-cases, or a combo | as search | same query + **explicit `sources`** by fact type |
| `mcp__perplexity-web__search_deep` | deep: market, competitive landscape, topic overview across 20+ sources | **slow, up to ~10 min** (daemon v1.3.0: does NOT block parallel `search`) | broad query with explicit goals and desired answer structure; NOT for one fact |

**Choosing `sources` in `search_advanced`:**
- `web` — general default (docs, blogs, news).
- `academic` — scholarly articles, papers, citations, formal methods.
- `social` — Reddit and forums: lived experience, gotchas, "who else hit this".
- combine (`web+academic`) when you need both official docs and formal confirmation.

> **Don't fire `search_deep` needlessly.** Up to ~10 min/query (daemon v1.3.0 no longer lets it block
> parallel `search`, but `deep` itself is still slow). For one fact/version/comparison `search` or
> `search_advanced` suffices. `deep` — only for synthesis across many sources (see "cost" in
> `verify-claims`/`context-economy`). Concurrency, pools and server error codes — section below.

## Concurrency & resilience of `perplexity-web` (daemon v1.3.0)

`perplexity-web` is a keyless bridge through a browser profile. **As of v1.3.0** queries are served by a
shared background **daemon** (owns Chromium): each query gets its own tab, up to `maxConcurrency` (default
**3**) in parallel. The daemon spawns on first call and shuts itself down on idle — the skill does NOT
start/stop it. The old "one shared profile, everything serialized" behavior remains as `--mode=legacy` (and
the old no-parallel ban applied only to it).

- **Parallel is now ALLOWED** — up to `maxConcurrency` (default 3) `perplexity-web` queries at once;
  `search_deep` (up to ~10 min) no longer **blocks** regular `search`. Above the limit — see `BROWSER_BUSY`
  below. (Other engines — context7/WebSearch — parallelize as before.) Tab isolation (each query reads its
  own tab, no cross-read/focus-steal) is covered by a daemon smoke test in `perplexity-web-mcp`.
- **Pools (`--pool`) are MCP config, not the query's concern** (pool choice — `mcp-advisor`): same pool
  across projects → shared daemon/window (parallel tabs); different pool → full isolation (own
  window/profile/daemon, each needs its own `login`). Pool `default` reuses the profile — login is not lost.

**Error semantics** (handle the error response, don't hang):

| In error text | Meaning | What to do |
|:--|:--|:--|
| `BROWSER_BUSY` | all tabs busy (saturation) | **retry after a few seconds**; appears only with `saturation` = `fail-fast`\|`hybrid` (with `queue` the request waits itself) |
| `TIMEOUT` | answer didn't make the timeout | retry / raise `--timeout` / split the heavy query |
| `LOGIN_REQUIRED` | login needed | call `login` |
| `PROTOCOL_MISMATCH` | a daemon of another version is running | let it idle-shut-down or restart the MCP server; do **NOT** fall back to legacy |

**Non-contract drop** (`ECONNRESET`, "Daemon connection closed before result") — NOT a table code but a
daemon crash: usually it couldn't launch Chromium (ms-playwright cache bumped → the pinned browser build was
pruned). `start.sh` now auto-falls-back to the newest installed ms-playwright Chromium, so this **self-heals
when any Chromium is installed**; you only need `npx playwright install chromium` in the `perplexity-web-mcp`
package when **none** is. Diagnose via the pool log `$XDG_RUNTIME_DIR/perplexity-web-mcp/<pool>.log`
(`Executable doesn't exist…`). Retry after the fix spawns a fresh daemon.

**Heavy query.** `search_deep` — up to ~10 min, but the daemon doesn't let it block parallel `search`. If the
answer still outgrows the timeout — split into narrow `search` (see "one query = one fact") or for deep
synthesis escalate to `research/research`. On a repeated `TIMEOUT`/`PROTOCOL_MISMATCH` — switch to
`WebSearch`+`WebFetch` (Claude-native) or `context7` for docs; don't get stuck on the server.

**API-key variant** (`@perplexity-ai/mcp-server`, `PERPLEXITY_API_KEY`; catalog —
`harness configs/claude-code/mcp/perplexity.json`) — still an alternative for heavy/frequent research with no
browser profile. With the daemon the keyless bridge closed the old lock/serialization — the key is taken for
speed/precision, not as a workaround for a locked profile.

> Contract — `perplexity-web-mcp` v1.3.0 (daemon, pools, error codes). The old "no parallel" workaround
> applied to the legacy bridge and is dropped. Route bridge-contract drift via `field-feedback-loop`.

> **Engine down → into the "Verification" footer.** If `perplexity-web` is locked/`TIMEOUT`/`PROTOCOL_MISMATCH`
> (or `context7` is empty) and a fact stayed unverified — don't swallow it silently: put it in the plan's
> "Verification" footer (format — `verify-claims`) so the owner can order a **re-pass**.

## Phrasing anti-patterns (fix before sending)

- **Bare subject, no version** — `Filament how to...` → add `v4`/range.
- **Multiple questions in one query** — engine answers fragmentarily; **split** into separate queries (one query — one fact/topic).
- **List with no limit** — `give me a list of packages` → `top-5 packages ...`.
- **Vague verb** — `tell me about X` → `compare X and Y by ...` / `show the syntax of ...`.
- **No timeframe on a fresh fact** — for versions/releases/"still current" add `as of 2025` / `current` / `latest major`.
- **Describing the tool inside the query** — don't tell the engine "search the web"; it does so itself.
- **Explaining "why I need it" instead of the subject** — context (slot 3) is stack/goal, not the task's backstory.
- **Answer comes back as human prose** → in the follow-up, repeat the AI-consumer contract (slot 6).

## Iteration (if the answer misses)

Phrasing is iterative. A miss → a **clarifying follow-up**, not a rewrite from scratch: `Only European
policies`, `Focus on v4, ignore v3`, `Give it as a table`, `Use primary sources only; if weak, list
limitations`.

Anti-hallucination (high-stakes): add a grounding rule to the query — `Cite every claim; if no data, say "I
don't know", don't guess`.

## Quality checklist (before sending the query)

- [ ] Subject carries a **version/range** (or explicit "current stable"); for a library — cross-checked with `composer.json`/`package.json` if available.
- [ ] Has **context/stack** and **specifics** (keywords/names), not a general question.
- [ ] One query = one fact/topic (multiple questions not glued together).
- [ ] List — **with a limit** (top-N); answer **format** specified.
- [ ] **Engine** chosen by fact type (context7 for API · Perplexity for practices/freshness · Web for a known URL) and **mode** (`search`/`advanced`/`deep`) by depth; `deep` not fired for one fact.
- [ ] In `search_advanced` `sources` chosen by fact type (academic/social/web).
- [ ] Fresh fact has a **timeframe**.
- [ ] `perplexity-web`: parallelized within `maxConcurrency` (default 3); on `BROWSER_BUSY`/`TIMEOUT` — retry/split, on `PROTOCOL_MISMATCH` — don't fall back to legacy; raw drop (`ECONNRESET`) → self-heals via `start.sh` if any Chromium is installed, else `npx playwright install chromium`; heavy synthesis → `research/research` or API-key variant.
- [ ] AI-consumer contract (slot 6) attached — or deliberately dropped if the answer is truly for a human.

## Related skills

- `general/verify-claims` — **when** to search/verify (claim classification, engine ladder, citation+confidence). This skill kicks in AFTER its "must verify" decision — and answers "how to phrase". `produces_for: verify-claims`.
- `general/mcp-advisor` — **what** to connect to a project (perplexity/context7/web as servers). Here — how to use what's already connected.
- `research/research` — deep research playbook (source-tiers, synthesis, knowledge graph); escalate here when the query grows into multi-step research.
- `general/context-economy` — each engine costs context/permissions; don't keep unused ones. `deep` is time-expensive — don't fire it needlessly.

<!-- provenance: phrasing rules cross-checked with Perplexity's official prompt guide
     (docs.perplexity.ai/guides/prompt-guide) + help-center "getting better answers",
     verified 2026-06-29 (WebSearch+WebFetch; perplexity-web profile was locked).
     search/advanced/deep modes — from the perplexity-web server tool schemas.
     Concurrency (daemon/pools/error codes BROWSER_BUSY·TIMEOUT·LOGIN_REQUIRED·PROTOCOL_MISMATCH) —
     from the perplexity-web-mcp v1.3.0 integration note, checked 2026-06-30. -->

<!-- ru-source-sha256: c209416e6ccfb1336b51fc9065e6811019904c6cd1101f3feec0229bb0069032 -->
