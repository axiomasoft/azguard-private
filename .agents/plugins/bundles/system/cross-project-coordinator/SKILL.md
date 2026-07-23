---
name: cross-project-coordinator
bucket: system
version: 0.1.0
description: "Orchestrate cross-project code analysis: walk linked projects (via topology), find dups/divergence/shared-package candidates, emit prioritized unification recommendations. Read-only — no edits, no PRs. Run for broad cross-project work, not a single repo."
risk: read
persona: architect
tags: [cross-project, coordination, refactoring, duplication, topology, orchestration, agents, tokens]
requires: [local-topology]
produces_for: [refactoring-plan]
outputs: []
snippets: []
adapters: [claude]
sha256: ""
---

# Cross-Project Coordinator

Walk linked projects to find what's duplicated/diverging across repos and propose where to extract it. Value = the cross-project axis (single-repo reviews miss it). Plugin ships read-only agent `code-coordinator`.

**Read-only.** This skill and the agent change nothing and open no PRs — output is a prioritized report; extraction is done separately by a human.

## When to activate

- Explicit cross-project request: "find common code between projects", "what to extract to a shared package", "where implementations diverged".
- Periodic constellation review (every N weeks).

**Anti-trigger:** single-repo work → `quality/code-review`, `quality/tech-debt-audit`, `quality/code-simplifier`, not this skill. Cross-project walk is expensive (reads multiple repos) — run only when the question is genuinely about links BETWEEN projects.

## Principles

- **Discovery.** Nodes and projects from topology (see `local-topology`): `~/.swissknifeman/topology.json` (`projects_base`/`brain`/`swissknifeman`) + `~/.swissknifeman/projects.json`. Brain gives doc-level links.
- **Exclusions & `.gitignore`.** List only git-tracked files — `git -C <proj> ls-files` respects `.gitignore`/`.git/info/exclude`/global ignore by construction; never read vendor/generated. Non-git tree → base hardcoded exclusion list. Never read secrets (`.env*`, `*.pem`, `*.key`, `**/secrets/**`). Details in `agents/code-coordinator.md`.
- **Criteria (seven types):** dup · divergence · drift (from registry) · copy-vs-dep · convention · docs · dep-skew. Don't reinvent single-repo criteria — take from `quality/`: `tech-debt-audit` (git-churn, impact×effort, ≤30%-of-capacity-on-debt rule), `code-review` (P0–P3), `code-simplifier` (dups/abstractions), `refactoring-plan` (Strangler Fig / Branch by Abstraction for extraction).
- **Discipline.** Evidence (`file:line` in each project, fragments adjacent); named extraction target; impact×effort priority and cap (~10 findings); no silent truncation.

## Algorithm

1. **Scope.** Take projects from prompt; else discover via topology. If many — name a subset (most linked / most changed) and say so.
2. **List files.** Per project: `git ls-files` minus base exclusions and `coordinator_ignore`. Record order of magnitude (files counted).
3. **Dispatch to Explore subagents per concern.** For a broad pass, give each concern/domain (DTO, HTTP clients, retry, enum, validation, config, deps) its own read-only `code-coordinator` agent. They return findings, not file bodies — context economy (per `laravel-architecture/laravel-subagents`). No worktree needed: all read-only, no edits.
4. **Correlate BETWEEN projects.** Main context dedups findings, collapses "one concept in N places", drops intra-project noise (out of scope here), assigns type and impact×effort.
5. **Prioritize & format.** Top = quick wins; per dup name the target and extraction strategy. Report format per `agents/code-coordinator.md`.

## Anti-patterns

- Running on a single repo (that's `quality/*`, not a cross-project walk).
- Reading vendored/generated (always via `git ls-files`).
- Findings without `file:line` and without a named target — "this should probably be refactored".
- Report bloat: 5 proven opportunities beat 30 guesses.
- Any edits/PRs from this skill — it's read-only; extraction is a human's job.

## Output

Prioritized report: findings (type, `file:line` per project, unification, impact×effort) + summary (top opportunities, proposed packages to extract to, what to align with the registry). Format per `agents/code-coordinator.md`.

## Quality checklist

- [ ] Scope set via topology; if a subset was taken — said explicitly.
- [ ] Files listed only via `git ls-files` (vendor/generated/secrets not read).
- [ ] Broad pass dispatched to Explore subagents per concern, not one context.
- [ ] Each finding has `file:line` in each project and a named extraction target.
- [ ] impact×effort priority assigned; list capped (~10), top = quick wins.
- [ ] No edits/PRs — report only.

## Links

- `agents/code-coordinator.md` — read-only cross-project analysis agent (Claude Code plugin channel only).
- `local-topology` (system bucket) — resolves nodes and projects via `~/.swissknifeman/topology.json`.
- `quality/tech-debt-audit`, `quality/refactoring-plan`, `quality/code-simplifier`, `quality/code-review` — criteria and extraction strategies.
- `laravel-architecture/laravel-subagents` — pattern of dispatching concerns to Explore subagents (return findings, not file bodies).
- `shared-memory` (system bucket) — where to record cross-project findings (dups, divergence, shared-package candidates) so other projects in the group see them.
- `scan-skills.sh` — if the pattern is worth promoting INTO the skill registry.

<!-- ru-source-sha256: 0de141c691f82026cf5a1aaab72a5f8b85e655a286074561c85863d11a51a1fc -->
