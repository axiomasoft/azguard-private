---
name: ecosystem-reuse-gate
bucket: architect
version: 0.1.0
description: "Gate against reinventing functionality when designing a new project/feature: before build-new, force a check against the ecosystem registry (radar) and run universal vendor-vs-build if-then rules. Default is reuse; build-new only when a rule explicitly fires. Activate when designing a new project, a large reusable feature, or picking a stack."
risk: read
persona: architect
tags: [ecosystem, reuse, vendor-vs-build, gate, architecture]
requires: []
produces_for: [new-project, tech-stack-selection]
outputs: [chat]
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Ecosystem Reuse Gate

An "advisory-with-teeth" gate: before designing a new functional unit from
scratch, force a check against the ecosystem's live registry ("radar") and
run universal vendor-vs-build rules. Default is reuse; `build-new` is allowed
only when one of the rules below explicitly fires.

This is a **mechanism**, not data: the skill does not know concrete project,
package, or vendor names — the path to the live ecosystem registry is passed
as a call parameter. The concrete binding to a specific ecosystem's data
lives in a separate project-scoped skill (see "Links").

## When to activate

Designing a new project, package, or large feature — easy to reinvent
something the ecosystem already solved via a vendor, a shared package, or an
existing domain layer. Without a forced gate, reuse discipline relies only on
the designer's memory and decays over time.

## Inputs

- Path to the live ecosystem registry (tech-radar) — a call parameter, never
  hardcoded.
- The functional unit being designed (what problem is being solved).
- Consumer project's language/runtime and constraints (license, self-host,
  compliance, domain).

## Algorithm

1. **Read the registry.** Load the ecosystem tech-radar from the given path.
   No path or unreachable file → report and stop — without the registry the
   gate cannot issue a verdict.
2. **Match the unit against the registry.** Find an exact or close match in
   the registry's "unit → component" map. No match → explicitly record "not
   found" (never a silent skip) and proceed to step 3 as a `build-new`
   candidate.
3. **Run the 7 if-then rules in order** (first rule that fires wins):
   1. Mature vendor/OSS with a compatible license and localizable self-host,
      covering an existing use in the ecosystem (Rule-of-3) → `use-vendor`.
   2. Same type of functionality already covered by an existing ecosystem
      package in the same language cluster → `use-package` (consolidate into
      the existing package, not a new one).
   3. Duplication confirmed, no suitable vendor exists (compliance /
      localization / domain-specificity) → `build-new` a thin adapter/hub —
      NOT reinventing the whole vendor functionality.
   4. A vendor covers the infrastructure/durability core, but a domain
      DSL/contract on top is needed → `build-new` a thin layer OVER the
      vendor, never duplicate the vendor's core.
   5. Two independent consumers (N=2), different language runtimes/load
      profiles, no shared application-level code → `keep-per-project`, do
      not force a merge.
   6. Regulation already dictates the vendor (a mandatory certified
      protocol/provider) → the vendor is not reconsidered, only the adapter
      layer is duplicated (see rule 3).
   7. A powerful cross-cutting concern consumed by several runtimes/products
      beyond the primary core, large enough to be its own project →
      `build-new` a **standalone package** with its own repo/philosophy, NOT a
      core module; the package owns its own vendor strategy, and the registry
      points to the package, not its internal vendor (core consumes it via a
      thin adapter).
4. **Issue the verdict.** One of `use-vendor` / `use-package` / `build-new` /
   `keep-per-project`, explicitly stating WHICH rule fired, with a quote from
   the registry (line/section) justifying the decision.
5. **Forbidden shortcut.** `build-new` without explicitly walking through
   rules 1–2 (and noting they didn't fire) is a forbidden output of this
   skill.

## Outputs

A reuse report in chat: unit → verdict → rule that fired → registry quote.
Not a standalone file — the decision is embedded into the design dialogue
(new-project / tech-stack-selection).

## Quality checklist

- [ ] Ecosystem registry read via the path parameter (not from memory, not
      hardcoded).
- [ ] Unit explicitly matched against the registry map (match or explicit
      "not found").
- [ ] All 7 rules run in order before a verdict is chosen.
- [ ] `build-new` never issued without stating which rule (3/4/6/7) fired.
- [ ] No concrete project/package/vendor example names in the skill body.

## Links

- `architect/package-contribution-protocol` — Rule-of-3 and the discipline of
  editing an already-existing shared package (mechanics not repeated here,
  reference only).
- The project-scoped binding to a specific ecosystem's data and registry path
  — a separate project skill that reads this mechanism via `requires`.

<!-- ru-source-sha256: cdc054f99fd30655f17c4477aaa68ed0c90c67d7827593c5e227f38eb5534e80 -->
