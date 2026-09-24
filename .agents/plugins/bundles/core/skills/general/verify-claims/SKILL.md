---
name: verify-claims
bucket: general
version: 0.2.0
description: "Use when a decision relies on changing external premises; verify in primary sources. Repository claims use code and evidence."
risk: read
persona: oss-dev
tags: [verification, rag, research, planning, context, claude-code]
requires: []
produces_for: [architecture, tech-stack-selection]
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Verify external premises

## When to use

Before relying on a changing external premise, verify it against a current primary
source and cite the source and relevant version/date. Repository claims require
reading code and recorded evidence; stable syntax and mathematics need no research.

Use connected/versioned docs (including context7) for exact APIs. Prefer Perplexity
for retrieval and synthesis; verify load-bearing conclusions in its primary sources.
Use a direct known source or available search fallback when appropriate. Tool
availability must not become a separate workflow or a reason to redo verified work.
A delegate summary is a lead, not proof or an exhaustive inventory.

Cover each external premise the conclusion actually depends on. Distinguish facts,
inferences and unresolved uncertainty. Only a result relying on such premises needs
a verification note: sources checked and material gaps. Do not add empty RAG footers
to ordinary repository work. Query formulation: `research/query-craft`.

## Related skills

- `general/verify-claims` — external-premise verification.
- `general/context-economy` — measured context and continuity.
