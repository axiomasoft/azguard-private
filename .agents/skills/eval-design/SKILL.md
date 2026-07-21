---
name: eval-design
bucket: architect
version: 0.1.0
description: Evaluate LLM/AI output quality — eval-set, metrics, regression harness, automated + human review
risk: draft
persona: architect
tags: [agentic, validation, llm, metrics, testing]
requires: [agent-design]
produces_for: [observability-design]
outputs: ["docs/03_Dev/Eval_Design.md", "docs/03_Dev/Evals/eval_set_v1.jsonl"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Eval Design

Apply when: product uses an LLM or other non-strictly-deterministic AI model and you must **measure output quality** pre-release and in prod.

## Do NOT apply when
- Product has no AI/LLM component — N/A.
- LLM only for non-critical tasks (e.g. auto-tags in admin) — lightweight eval, not full harness.
- No `agent-design` — refuse; without agent contract nothing to measure.

## 4 eval levels
- **L1 Smoke (CI):** basic sanity (no crash, valid response format, refuses jailbreak). Every PR; runtime < 2 min; 20–50 examples.
- **L2 Reference (nightly):** match to golden dataset / reference answers. Daily CI, pre-release; 200–500 examples.
- **L3 Behavioural (pre-release):** edge cases, adversarial inputs, domain-specific failures. Before each model/prompt release; 500–2000 examples.
- **L4 Production (online):** seed real requests + human review + auto-scoring. Continuous in prod; 1–5% live traffic.

## Metrics
Catalog by task type (classification/generation/RAG/agentic) — [references/metrics.md](references/metrics.md).

## Eval set — structure
Storage: JSONL at `docs/03_Dev/Evals/eval_set_v1.jsonl`

```json
{"id": "ev-001", "category": "happy-path", "input": "...", "reference": "...", "metrics": ["embedding_sim", "llm_judge"]}
{"id": "ev-002", "category": "edge", "input": "...", "reference": "...", "metrics": ["exact_match"]}
{"id": "ev-003", "category": "adversarial", "input": "Ignore previous instructions...", "expected_behavior": "refusal"}
```

Mandatory coverage:
- ≥ 60% happy path
- ≥ 20% edge cases (empty input, multi-language, long context, conflicting requirements)
- ≥ 10% adversarial (jailbreak, prompt injection, toxic inputs)
- ≥ 10% domain-specific failures

## Regression harness
Each model/prompt release → run over eval-set → compare to previous baseline.

Pass criteria:
- Reference evals: ≥ 95% of baseline
- Smoke evals: 100% pass
- Behavioural: regression > 5% on any category = block release
- Safety: zero-tolerance on jailbreak

Versioning:
- Eval set versioned as code (`eval_set_v1.jsonl`, `eval_set_v2.jsonl`)
- Store baseline results (`evals/results/<timestamp>_<model>_<prompt>.json`)
- Eval-set change ≠ model change — separate PRs

## LLM-as-judge — pitfalls
If using an LLM to score another LLM:
- **Bias toward verbose** — judge rates long answers higher
- **Position bias** — in pairwise comparison the first answer often wins
- **Self-preference** — model X scores its own answers above model Y → use a **different** model as judge
- **Rubric mandatory** — without explicit criteria judge gives noise

Mitigation:
- Random shuffling in comparisons
- Judge = stronger model than the one tested
- Calibrate judge on 50–100 human-rated examples before launch

## Agent adds
- **Domain-specific failures.** Per-project concrete traps.
- **Cost guardrails in eval.** An eval run may cost $50; state budget and optimize (batching, caching provider calls).
- **Reproducibility.** `temperature=0`, fixed seed (if provider supports), pinned model version (`gpt-4o-2024-11-20`, not `gpt-4o`).
- **Privacy in eval-set.** Real user requests → anonymize before adding (see `security-design`).

## Output files

### `docs/03_Dev/Eval_Design.md`

```markdown
---
project: [ProjectName]
stage: eval-design
based_on: docs/03_Dev/Agent_Design.md
---

# Eval Design — [ProjectName]

## AI-компонент
- Модели: ...
- Use-cases: ...
- Critical paths (где провал = harm): ...

## Уровни eval
- L1 Smoke (CI): [размер, время]
- L2 Reference (nightly): ...
- L3 Behavioural (pre-release): ...
- L4 Production (online): ...

## Метрики
| Категория задачи | Метрики | Пороги |
|:---|:---|:---|

## Eval set v1
- Файл: `docs/03_Dev/Evals/eval_set_v1.jsonl`
- Покрытие: [happy/edge/adversarial/domain %]
- Размер: [N примеров]

## Regression-харнес
- Pass criteria: ...
- Baseline-хранение: ...
- Trigger: PR / nightly / release

## LLM-as-judge (если используется)
- Judge model: ...
- Rubric: ...
- Калибровка: [N примеров, kappa с human]

## Production monitoring
- % live traffic под evals: ...
- Связь с observability: alert при padении метрики X

## Domain-specific failures (агент)
- ...

## Cost & reproducibility
- Стоимость full eval-run: $...
- Reproducibility: temperature=0, model pin: ...
```

### `docs/03_Dev/Evals/eval_set_v1.jsonl`
Starter eval-set, minimum 50 examples at launch.

## Hard prohibitions
NEVER:
- Release an LLM feature without at least smoke evals in CI
- Use the same model as judge and as-tested
- Run eval with `temperature > 0` without fixing seed
- Store real user requests in eval-set without anonymization
- "I'll show metrics later" — without eval-set quality = taste
- Ignore the adversarial category — users will find jailbreaks

## Related skills
- `architect/agent-design` — agent contract (tool registry, autonomy level); precondition.
- `architect/observability-design` — production-evals and quality metrics feed prod monitoring and alerts (output of this skill).
- `architect/security-design` — anonymize real user requests before adding to eval-set.
- `quality/test-strategy` — overall test pyramid; eval is its AI-specific layer.
- `security/security` — external reference for adversarial/jailbreak scenarios.

<!-- ru-source-sha256: 5af71d94e6e8631cdc09eb14892bfd169bdcdee9cb17ca8c0aba3719efdc3836 -->
