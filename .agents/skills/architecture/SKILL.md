---
name: architecture
bucket: architect
version: 0.1.0
description: System architecture, stack choice, ADR, C4 diagrams, deployment, scalability
risk: draft
persona: architect
tags: [architecture, adr, c4, scalability, deployment]
requires: [brd, tech-stack-selection]
produces_for: [data-schema, api-design, security-design, agent-design, observability-design, legal-compliance]
outputs: ["docs/03_Dev/Architecture_[Name].md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Architecture Design

Apply when: task on architecture, stack choice, ADR, technical design.

## Precondition

Write architecture ONLY after approved BRD. No BRD → first read `.ai/skills/pm/brd.md`.

## Architecture doc format

```
1. Context & constraints (from BRD)
2. Options considered (min 2–3)
   - Option A: [name]
     - Pros: ...
     - Cons: ...
     - Cost / complexity: ...
     - business_rationale: how the option serves the BRD goal/constraint
   - Option B: ...
3. Chosen solution + rationale
   - Why this one, not the others
   - Which risks we accept consciously
4. Component diagram (Mermaid)
5. Integrations & external dependencies
6. ADR records for non-trivial decisions → `docs/03_Dev/ADR/`
```

## Rationale rule

Each architectural decision contains:
- **What chosen**
- **Why** (via business logic, not just "it's popular")
- **What rejected and why**

**Mandatory:** every Options block carries a `business_rationale` field — how the option serves the BRD goal/constraint. An option without `business_rationale` is not admitted to the comparison (don't pick a stack "because popular/familiar").

## ADR (Architecture Decision Record)

Create ADR in `docs/03_Dev/ADR/ADR-NNN_name.md` when:
- Decision is non-trivial and can be contested
- Real alternatives with similar trade-offs exist
- Decision affects multiple components

ADR format:
```
## Context
## Decision
## Alternatives
## Consequences
```

## Cross-project check

Before designing, check `02 - Knowledge/` and vault map:
- Similar architecture in another project → reuse pattern
- Reference in `95 - References/` → link via `[[file]]`

## Full architecture doc template

Create in `docs/03_Dev/Architecture_[Name].md`:

```markdown
# Architecture: [ProjectName] — [component/context]

## 1. Context & constraints
[From BRD: goal, actors, key non-functional requirements]
- Load: [RPS / DAU]
- Latency SLO: [ms for p95]
- Availability: [% uptime]
- Budget: [infra cost target]

## 2. Options considered

### Option A: [name]
- Pros: ...
- Cons: ...
- Complexity: [Low / Medium / High]
- business_rationale: [how the option serves the BRD goal/constraint]

### Option B: [name]
- ...

## 3. Chosen solution
[What chosen and why — via business logic, not "it's popular"]
Risks accepted consciously: ...

## 4. Component diagram (Mermaid)
[C4 Level 2 — containers]

## 5. Deployment Architecture
[Where it runs: cloud/VPS, containers, serverless, region]
[Infra diagram: LB → App → DB → Cache → Queue]

## 6. Integrations & external dependencies
| System | Purpose | Integration type | Dependency SLA |
|:---|:---|:---|:---|
| [Name] | [why] | REST/webhook/SDK | [uptime] |

## 7. Scalability
- [ ] Stateless app servers? (horizontal scale)
- [ ] Bottleneck identified? (DB / external API / computation)
- [ ] Caching strategy? (what we cache, TTL, invalidation)
- [ ] Async for long operations? (queue / background jobs)

## 8. ADR
[Links to records in docs/03_Dev/ADR/]
```

## Security Gate

**If product handles user data, finances or third-party integrations** → read `.ai/skills/architect/security-design.md` in parallel with this skill. Output — `docs/03_Dev/Security_Design.md`.

## Hard prohibitions

FORBIDDEN:
- Design architecture without approved BRD
- Choose technology without comparing min 2 options
- Leave an Options block without `business_rationale` (choosing by "popularity/habit")
- Leave SLO/load undefined ("figure out later")
- Create architecture doc without Mermaid diagram
- Skip Security Gate for products with PII
- Commit external facts (library versions, API behavior, SLA, pricing) from memory — verify via `verify-claims` first

## Related skills

- `pm/brd` — approved BRD as mandatory precondition.
- `architect/tech-stack-selection` — stack choice with trade-off matrix goes BEFORE this skill.
- `architect/data-schema`, `architect/api-design` — data & contract detailing after architecture.
- `architect/security-design` — Security Gate, run in parallel for products with PII/money.
- `architect/observability-design` — prod observability (SLO/SLI/runbooks) on top of approved architecture.
- `architect/agent-design` — Agent Design section for products with agents.
- `architect/legal-compliance` — regulatory checklist based on architecture (where data is stored).
- `php/laravel-structure`, `laravel-architecture/modular-architecture` — implementing architecture in concrete Laravel code.

<!-- ru-source-sha256: 5b933f598e9ad4f143807e017be3b16bdc8b6309cae5ec1a8c1562376642a21a -->
