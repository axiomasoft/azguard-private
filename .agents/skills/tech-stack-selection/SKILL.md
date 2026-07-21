---
name: tech-stack-selection
bucket: architect
version: 0.1.0
description: Tech stack selection with trade-off matrix before architecture design. ADR-first step
risk: draft
persona: architect
tags: [stack-choice, architecture, adr, trade-off]
requires: [brd, ecosystem-reuse-gate]
produces_for: [architecture, oss-development, release-engineering]
outputs: ["docs/03_Dev/Tech_Stack_Selection.md", "docs/03_Dev/ADR/ADR-001_tech_stack.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Tech Stack Selection

Use when: must **select** language / framework / DB / infra-platform before architecture design. Not `architecture` (that designs components on a chosen stack).

Run **after `brd`** (need NFRs + load) and **before `architecture`**.

## Do NOT apply
- Stack already fixed by user or ADR → refuse, go to `architecture`.
- OSS project single-platform by definition (JS-only / PHP-only) → stack chosen earlier, don't repeat.
- Project extends existing code → stack inherited; do ADR on specific submodule if needed.
- No BRD / NFRs → refuse, send to `brd`.

## 6 selection axes (consider all)

| Axis | Choose among | Affects |
|:---|:---|:---|
| **Backend language** | TS/Node, Python, Go, Rust, PHP, Java | Performance, hiring, ecosystem |
| **Backend framework** | NestJS, FastAPI, Laravel, Gin, Actix, Spring | Dev speed, conventions |
| **DB (OLTP)** | Postgres, MySQL, SQLite, MongoDB, DynamoDB | Transactions, scale, cost |
| **Frontend** | Next.js, Nuxt, SvelteKit, Flutter, native | UX, SEO, time-to-market |
| **Infra / deployment** | Vercel, Cloudflare, AWS, GCP, VPS, k8s | Cost, latency, ops complexity |
| **Async / queue** | BullMQ, SQS, RabbitMQ, Temporal, none | Throughput, reliability |

Extra axes if needed: cache (Redis/KeyDB/in-memory), search (Meilisearch/Elastic), analytics, ML serving, payments.

## Algorithm (5 steps)

### Step 1. Hard constraints
Before the matrix — run `ecosystem-reuse-gate` against the ecosystem radar: reusing an existing component can rule out whole axes before the trade-off analysis even starts.

Extract from BRD + project:
- **Regulation** — e.g. fintech requires data residency; AI-tutoring requires COPPA/age limits.
- **Team competencies** — solo founder on PHP must not take Rust "because trendy".
- **Infra budget** — pre-seed: serverless / managed; series-A+: k8s allowed.
- **Explicit stack requirements from BRD** — e.g. "must work with iOS Live Activities" = Swift native.

If any axis hard-fixed → record in **Constraints**, exclude alternatives.

### Step 2. Trade-off matrix (mandatory)
For **min 2 options per axis** that passed Step 1, fill:

| Criterion | Option A | Option B | Option C |
|:---|:---|:---|:---|
| Performance (RPS per 1 vCPU) | | | |
| Time-to-market (1 dev, weeks to MVP) | | | |
| Hiring cost (senior median $/mo) | | | |
| Ecosystem maturity (rating of key libs) | | | |
| Operability (cloud-native? managed?) | | | |
| Lock-in (can leave in 2 weeks?) | | | |
| Infra cost at baseline load | | | |
| Match with other vault projects | | | |

Concrete numbers > epithets. Unknown → mark `?`, leave as open question.

### Step 3. Axis compatibility
Don't pick axes independently. Check:
- **Backend + DB** — first-class ORM/driver?
- **Frontend + Backend** — type-replication supported (tRPC, GraphQL, OpenAPI)?
- **Infra + language** — cold start, runtime support (Cloudflare Workers ≠ Node-only).
- **Queue + Backend** — native SDK or bridge needed?

If stack is a "zoo" → name integration cost explicitly.

### Step 4. Recommendation + migration path
- **MVP starting stack** — one concrete combination, explicit.
- **Replaceable without rewrite** — components with clean interfaces (DB, queue, cache).
- **NOT replaceable** — usually backend language + UI framework. The "hard core".
- **Revisit triggers** — concrete metrics (e.g. "at > 10k RPS revisit cache strategy").

### Step 5. ADR-001 — record decision
Create `docs/03_Dev/ADR/ADR-001_tech_stack.md` now, not "someday". First ADR of project.

## Agent adds itself
- **Stage antipatterns.** Pre-seed solo founder + microservices + k8s = red flag. Name explicitly.
- **Hidden costs.** SQLite seems free — until horizontal scale needed. Postgres Aurora scales — but serverless v2 cold-start = 12+ s.
- **Hire-ability.** Stack must be hiring-compatible with project ICP (if team planned).
- **Vault-project compat.** If another project already on TS/Postgres — insight "don't breed a zoo" and reuse patterns (see `02 - Knowledge/`).
- **Open-source readiness.** If OSS spin-off planned — account for dependency licenses (see `dependency-audit` in OSS track).

## Output file structure

### `docs/03_Dev/Tech_Stack_Selection.md`

```markdown
---
project: [ProjectName]
stage: tech-stack-selection
based_on_brd: docs/03_Dev/BRD.md
produces_input_for: [Architecture_[Name].md, ADR-001_tech_stack.md]
---

# Tech Stack Selection — [ProjectName]

## Constraints (из BRD и реальности)
- Регуляторика: ...
- Команда: ...
- Бюджет infra: ...
- Жёстко заданные элементы стека: ...

## Trade-off матрица: backend язык
[таблица]

## Trade-off матрица: БД
[таблица]

## Trade-off матрица: frontend
[таблица]

## Trade-off матрица: infra
[таблица]

## Совместимость осей
- Backend + БД: ...
- Frontend + Backend: ...
- Infra + язык: ...

## Стартовый стек MVP
- Backend: ...
- БД: ...
- Frontend: ...
- Infra: ...
- Queue: ...

## Заменяемые компоненты
- [список с указанием стоимости миграции]

## Жёсткое ядро (не меняем без переписывания)
- ...

## Триггеры пересмотра
- При [метрика] → пересмотреть [компонент]

## Антипаттерны для стадии (агент)
- ...

## ADR
→ `docs/03_Dev/ADR/ADR-001_tech_stack.md`
```

### `docs/03_Dev/ADR/ADR-001_tech_stack.md`

```markdown
# ADR-001: Tech stack selection for [ProjectName]

## Status
Accepted — YYYY-MM-DD

## Context
[краткая выжимка из BRD + Tech_Stack_Selection.md]

## Decision
[стек одной таблицей]

## Alternatives considered
[главные альтернативы по каждой оси и почему отклонены]

## Consequences
- Положительные: ...
- Отрицательные / accepted риски: ...
- Триггеры для следующего ADR: ...
```

## Hard prohibitions at this stage
NEVER:
- Pick stack "because trendy / favorite".
- Skip trade-off matrix for at least backend and DB.
- Take k8s/microservices on pre-seed without hard justification.
- Leave "decide later" — `architecture` will suffer.
- Not create ADR-001 immediately.
- Pick a stack/library on stale memory — verify current versions/maintenance/best-practice via `verify-claims` first.

## Related skills
- `pm/brd` — NFRs + load as precondition.
- `architect/ecosystem-reuse-gate` — step 1: check the ecosystem radar before the trade-off matrix.
- `architect/architecture` — component design on chosen stack (output of this skill).
- `oss-dev/oss-development`, `oss-dev/release-engineering` — OSS track relying on chosen stack (output).
- `oss-dev/dependency-audit` — dependency licenses for OSS spin-off.
- `php/laravel-structure` — if Laravel chosen: project structure canon for next step.

<!-- ru-source-sha256: f029fc24b100bce2dd641aa2e82defbef25af00c618995b6ade6b568176b7e9a -->
