---
name: brd
bucket: pm
version: 0.1.0
description: Business Requirements Document: functional requirements, user stories, acceptance criteria
risk: draft
persona: pm
tags: [requirements, template]
requires: [business-process]
produces_for: [architecture, data-schema, product-roadmap, requirement-critic, prd-from-brd, tech-stack-selection]
outputs: ["docs/03_Dev/BRD.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: BRD Writing

Apply when: task is to write or update BRD (`docs/03_Dev/BRD.md`).

BRD = WHAT the system does, not HOW. Audience: developers, in-repo AI agents, technically-literate investors.

## Ask BEFORE writing (only if not already known from `docs/02_Workflow/`)
- Key scenarios: who, what, when
- Exceptions and edge cases
- Integrations with external systems
- User roles and permissions
- Availability / performance requirements

## BRD structure
```
1. Document goal and context
2. Actors and roles
3. Functional requirements (by module)
   - User stories: "As a [role], I want [action], so that [goal]"
   - Acceptance criteria per story
   - Edge cases (agent adds these)
4. Non-functional requirements
5. Integrations
6. Open questions (link to [[Открытые вопросы]])
```

## Agent adds itself
- User stories the user didn't mention but that logically follow
- Edge cases: user offline? data invalid? concurrent request?
- Requirement conflicts — name them explicitly, propose a resolution

## Hard prohibitions at BRD stage — FORBIDDEN
- SQL table schemas
- Classes, methods, function signatures
- In-code data structures
- Concrete implementation patterns (Repository, CQRS, etc.)
- Technology choices (except where it is a business requirement)

## Related skills
- `pm/business-process` — input for BRD: FSM/workflow underpinning functional requirements (required BEFORE BRD).
- `pm/requirement-critic` — audit finished BRD (completeness, conflicts, MoSCoW) before moving to architecture.
- `pm/prd-from-brd` — next layer: expands BRD into detailed user stories with AC and UX.
- `pm/product-roadmap` — phasing requirements (MVP → v1 → v2) on top of BRD.
- `architect/architecture`, `architect/data-schema`, `architect/tech-stack-selection` — third layer (HOW), consume BRD.
- `general/spec-interview` — one-question-at-a-time interview technique to gather missing requirements before BRD.

<!-- ru-source-sha256: a56d6f35e68ab49fc39eda0b5bc2cfacbbdaf920fba88b8f421f7dbc21d1602d -->
