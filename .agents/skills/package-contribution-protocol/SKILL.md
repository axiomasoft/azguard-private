---
name: package-contribution-protocol
bucket: architect
version: 0.1.0
description: "Gate against bloating a depended-on package from a consumer project: Rule of Three, proposal→ADR, minimal contract, dependency direction, SemVer, read-only boundary. Activate when a project task needs editing a shared package, on 'extract common', and when adding a class/provider/interface/abstraction to a depended-on package."
risk: read
persona: architect
tags: [governance, package, monorepo, contribution, semver, anti-bloat, boundary]
requires: [architecture, api-design, task-brief-template, anti-drift]
produces_for: []
outputs: [chat]
snippets: [proposal-issue.md, adr-template.md, claude-md-priority.md, permissions-deny.md]
adapters: [claude, cursor, fable]
sha256: ""
---

# Package Contribution Protocol

Consumer project consumes its packages as a **read-only dependency**. Editing a package = separate, deliberate, agreed action — never a side effect of a product task. Universal: "package" = any shared package (composer/npm/…); "consumer" = any dependent project. No concrete package/project names in body — principles only.

## When to activate

- Consumer task blocked by missing package functionality.
- Phrasing "extract common", "make reusable", "into core/into package".
- Adding to a depended-on package: a new class, service provider, interface, base class, parameter, or public method.
- `git diff` touched a depended-on package dir (`vendor/**`, path-repo `packages/**`, monorepo package) within a product task.

## Main question (ask before ANY package edit)

> Is this used in **two or more independent consumers right now**, AND **can no consumer** solve it on its own?

If "no" to either part → code stays in the consumer with a `TODO` + issue link. Rule of Three (Fowler) + YAGNI: generalize on the third repetition, not on imagined future.

## Stop and ask / delegate to subagent

Default: **ask, don't extend**. Before introducing a new class/provider/interface/abstraction:

1. **Structure survey.** Find an existing extension point in the package (contract, event, config, trait). Often nothing new is needed.
2. **If in doubt — small task/subagent go/no-go.** Run one narrow step (or subagent) with a single goal: (a) survey — what in the package already solves it; (b) judge by YAGNI / Rule of Three / Law of Demeter; (c) verdict **go/no-go** with one-line rationale. Do not mix recon with implementation.
3. **Verdict `no-go`** → solve consumer-side (see "Local workaround").
4. **Verdict `go`** → two-stage gate below.

## Two-stage gate

**Stage 1 — Proposal issue** in the package repo (template `snippets/proposal-issue.md`): which consumer initiates, source task, why it can't be solved in the consumer, contract sketch, alternatives considered (minimum 2 — e.g. "event" instead of "new method", "trait" instead of "base class", "config" instead of "new parameter").

**Stage 2 — Review + ADR** (only after proposal approval): branch in the package → ADR (`snippets/adr-template.md`) → implement the **minimal** contract → PR with mandatory manual review by the package maintainer.

## What does NOT go into the package

| Antipattern | Symptom | Correct place |
|---|---|---|
| "Shared BaseService" | class without invariant, convenience only | trait/helper in consumer |
| Consumer configuration | params specific to one project | consumer config |
| Proxy method | wraps one package method without logic | call directly |
| Temporary storage | code "until we figure out where" | issue + refactor backlog |
| God-interface | >5–7 methods without clear cohesion | split into contracts |

## Dependency direction

- Package **does not import** consumers (Dependency Inversion: consumers depend on package abstractions, not vice versa).
- No reverse-dependency "consumer → consumer via package": package must not become a hidden channel between products.
- Changing `Contracts/` (public interfaces) without a major version — forbidden.

## Local workaround without editing the package

Until need is confirmed in 2+ consumers:

1. Implement consumer-side (`app/`, `Modules/`) via `extends`, events/Observer, or a package extension point.
2. File an issue in the package using the proposal template.
3. Put a `TODO` with the issue link at the local implementation site.

## Package contract SemVer

If a package edit is agreed:

- **patch** — bugfix, no public API change.
- **minor** — new public APIs; backward compatibility preserved.
- **major** — breaking change: consumers must adapt code.
- "Is it breaking?" checklist: public method/class removed/renamed? signature/return type changed? input tightened or output weakened? data format/event name changed? config key removed/renamed? Any "yes" → major.
- Deprecation: mark old (`@deprecated` + deadline), do not remove in minor; remove in next major with a migration guide.
- Every major: ADR + CHANGELOG entry ("Breaking:") + short migration guide.

## Five markers of a healthy package

1. Every public method used in 2+ consumers.
2. Every breaking change has an ADR and a major bump.
3. Package imports nothing from consumers.
4. All extensions via contracts/events, not monkey-patching.
5. Package `git log` — only explicitly agreed PRs (no "silent" edits from product tasks).

## Quality checklist

- [ ] Main question asked (2+ consumers + not solvable locally).
- [ ] Before a new abstraction — package structure survey; if in doubt, go/no-go via small step/subagent.
- [ ] `no-go` → local implementation + issue + TODO; `go` → proposal → ADR → PR.
- [ ] ≥2 alternatives considered (event/trait/config vs new contract).
- [ ] Contract minimal; dependency direction not violated.
- [ ] Package version per SemVer; breaking → ADR + CHANGELOG + migration guide.

## Distribution (hybrid)

- Base reflex "ask before extending" already ships to all projects via CORE skill `anti-drift` — no separate install.
- This skill (deep protocol) connects **selectively** to projects that work on their packages, reinforced by a manual "top priority" in the consumer's root CLAUDE.md — generic block ready in `snippets/claude-md-priority.md`.
- Hard write boundary to package — permissions preset `product-boundary` (`harness permissions --preset product-boundary`); fragment and path-repo variant in `snippets/permissions-deny.md`.

## Related skills

- `general/anti-drift` — base reflex "ask before extending", YAGNI, surgical edits (CORE, all projects).
- `quality/code-simplifier` — speculative-generality checklist on the fresh diff.
- `architect/architecture` — ADR and ≥2-variant comparison for non-trivial decisions.
- `architect/api-design` — designing and evolving the public contract.
- `general/task-brief-template` — scope/out-of-scope/DoD before starting a package edit.
- `system/cross-project-coordinator` — finding duplicate candidates for the shared package.

## Links

- snippets/proposal-issue.md
- snippets/adr-template.md
- snippets/claude-md-priority.md
- snippets/permissions-deny.md

<!-- ru-source-sha256: 3473f0852ccf085d0c86891d013c073593549014b5c8d77d5a04d9841234e0f3 -->
