---
name: agent-design
bucket: architect
version: 0.1.0
description: Agent design, agentic loop, tool contracts, harness, permission gates
risk: draft
persona: architect
tags: [agentic, architecture, security, validation]
requires: [architecture]
produces_for: [eval-design, observability-design]
outputs: ["docs/03_Dev/Agent_Design.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Agent Design

Apply when: agent design, agentic workflow, tool contracts, harness design, agentic loop audit, MVP agent blueprint.

## Precondition

- Agent architecture written **only after approved BRD**. No BRD → first read `.ai/skills/pm/brd.md`.
- PII/financial products → run `.ai/skills/architect/security-design.md` in parallel.

## Key principle

> Model **proposes** actions. Harness validates, authorizes, executes, records.

Model never executes directly. Every tool call returns a result — even a refusal or error.

## 7 Loop Invariants (mandatory checklist)

Loop is correct iff all 7 hold:

- [ ] **One-to-one** — each tool call returns exactly one result block
- [ ] **Pre-execution validation** — schema and params validated before execution
- [ ] **Permission gate** — risky actions pass policy-check outside the model
- [ ] **Bounded results** — tool results size-limited
- [ ] **Hard budgets** — limits exist: steps, tokens, time, cost
- [ ] **Evidence-based conclusions** — final answer backed by loop observations, not assumptions
- [ ] **Structured failure** — errors and refusals structured and logged

## Autonomy levels (choose before design)

| Level | Mode | When |
|:---|:---|:---|
| 0 | Answer-only | Answers only, no actions |
| 1 | Draft-only | Prepares, does not send |
| **2** | **Approval-gated** | **Default — acts after confirmation** |
| 3 | Supervised autonomous | Autonomous, with human review gate |
| 4 | Long-running autonomous | Full autonomy with checkpoints |

Default Level 2. Move to 3–4 only after proven reliability at 2.

## MVP Blueprint — 15 components

```
1.  Domain objective      — what agent does and does not do
2.  Autonomy level        — one of 0–4 (choose explicitly)
3.  Provider-neutral loop — model wired via contract, not directly
4.  Typed tool registry   — each tool: schema + timeout + output limit
5.  Permission matrix     — allow / deny / ask_user / approval_required
6.  Structured results    — tool call always returns typed result
7.  Context architecture  — what enters context, in what order
8.  External memory       — state stored outside conversation
9.  Auto-compaction       — compress context before limit exceeded
10. Planning mode         — approval-gated before multi-step tasks
11. Goal-like loops       — checkpoints + measurable done condition
12. Skills/connectors     — external APIs wired as typed skills
13. Cost-aware layout     — stable prefix + cache-optimized order
14. Observability         — traces: operational events, not reasoning
15. Evals                 — test harness, not just model
```

## Tool & Permission Design

Risk taxonomy:

| Class | Examples | Policy |
|:---|:---|:---|
| read-only | search, file read, metadata | allow |
| write-local | create files, drafts | allow |
| write-external | email, webhook, push | approval_required |
| financial | payment, transaction | approval_required + audit |
| destructive | delete, drop, wipe | ask_user |

Draft-commit pattern — mandatory for financial / destructive / regulated:

```
draft_action() → preview → user_confirm() → commit_action()
```

Parallelism: independent read-only ops only. Writes, sends, deletes — always sequential.

## Cost & Caching

- **Stable prefix** — system prompt + static instructions first (cached)
- **Dynamic suffix** — conversation history last (not cached)
- **Append-only history** — never rebuild history, preserve cache reuse
- **Deterministic tool order** — tool order in schema never changes
- **Budget limits** — all 4 mandatory: step_limit, token_limit, time_limit, cost_limit

## Pre-launch Checklist

```
Security:
- [ ] Test prompt injection (external content as data, not instruction)
- [ ] Test approval bypass (attempt to skip permission gate)
- [ ] Test context overflow (behavior on overflow)
- [ ] Secrets do not reach model context

Reliability:
- [ ] Traces and evals defined before deploy
- [ ] Shadow mode / limited rollout on first release
- [ ] Cost telemetry works
- [ ] Structured errors for all tool failures

Quality:
- [ ] Eval covers: injection, misuse, bypass, overflow
- [ ] Harness tested independently of model
```

## Agent section format in Architecture doc

Add section `## Agent Design` to `docs/03_Dev/Architecture_[Name].md`:

```markdown
## Agent Design

### Уровень автономии
[Level X: описание]

### Tool Registry
| Tool | Класс риска | Схема | Timeout | Output limit |
|:---|:---|:---|:---|:---|

### Permission Matrix
| Действие | Политика | Условие |
|:---|:---|:---|

### Loop Budget
- step_limit: N
- token_limit: N
- time_limit: Xs
- cost_limit: $N

### Context Architecture
[Что в system prompt, что в history, что из внешней памяти]
```

## Hard prohibitions

DO NOT:
- Give model direct execute access without harness
- Parallelize write/send/delete ops
- Run at Level 3–4 without proven Level 2
- Store secrets in model context
- Deploy without traces and evals
- Leave budgets undefined

## Reference

`https://github.com/DenisSergeevitch/agents-best-practices` — full reference files: agentic-loop, tools-and-permissions, mvp-blueprint, security-evals, prompt-caching, workflow-orchestration.

## Related skills

- `architect/architecture` — overall architecture doc embedding the Agent Design section (precondition).
- `architect/eval-design` — measuring agentic output quality; eval harness tests harness, not just model (this skill's output).
- `architect/security-design` — STRIDE, prompt-injection, secrets outside model context — in parallel for PII/money agents.
- `architect/observability-design` — traces, token-cost, latency per model, jailbreak metrics for prod agent.
- `ai/ai-agents` — external compendium on building agents/MCP/RAG (analysis, not mirror).
- `security/security` — external defensive playbook on prompt injection and tool-use safety.

<!-- ru-source-sha256: 023165b355d45b5d452365c49ee3da43f9cd83ceecc4e6a67e6128928dc0a0d6 -->
