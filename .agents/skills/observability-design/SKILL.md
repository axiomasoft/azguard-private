---
name: observability-design
bucket: architect
version: 0.1.0
description: Logging, metrics, tracing, alerting. SLO/SLI, error budget, runbooks for prod-readiness
risk: draft
persona: architect
tags: [observability, logging, metrics, tracing, alerting, slo, runbook]
requires: [architecture]
produces_for: [runbook, incident-response, capacity-planning]
outputs: ["docs/03_Dev/Observability_Design.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Observability Design

Apply when: architecture approved, design prod observability **before** deploy. 4 pillars: logs, metrics, traces, alerts + SLO/SLI + runbooks.

## Do NOT apply when
- Architecture not approved → refuse, go to `architecture`.
- Pre-prototype / hello-world (overkill). Apply once there are real users.
- OSS library without deploy → N/A. OSS service (daemon) → applies.
- Just "add sentry" → that's a roadmap task, not observability design.

## 4 pillars
logs/metrics/traces/events — detail in [references/four-pillars.md](references/four-pillars.md).

## SLO / SLI / Error budget
Minimum for prod-readiness — define:

| Service | SLI | SLO target | Error budget (30d) |
|:---|:---|:---|:---|
| API gateway | availability | 99.9% | 43.2 min down |
| API gateway | latency p95 | < 300ms | < 5% of requests above |
| Background jobs | success rate | 99.5% | 0.5% failed |
| LLM endpoint | success rate | 99% | 1% (models fail) |

**Error budget policy:**
- Burn rate alerts: >2% of budget burned in last hour → P1
- Monthly budget exhausted → stop features, fix reliability

## Cost-tier planning
State tier explicitly in Output. No tier-2 on pre-seed.

**Tier 0 (pre-MVP, $0/mo):**
- Logs: stdout + cloud-native collector (CloudWatch / Vercel logs)
- Metrics: hand-rolled via prometheus exporter
- Traces: skip or 1% sampling in OTel-collector
- Alerts: cron-based health checks

**Tier 1 (MVP in prod, ~$50/mo):**
- Better Stack / Highlight / Sentry — single platform
- Trace sampling 10%, logs retention 7d

**Tier 2 (post-PMF, $200–500/mo):**
- Full OTel stack: Tempo + Loki + Prometheus + Grafana (self-host)
- Or Datadog APM on critical services

## Runbooks
Per P1-alert → runbook `docs/03_Dev/Runbooks/<alert_name>.md`:

```
1. Symptom (what we see)
2. Possible causes (top-3)
3. Diagnostic commands (curl, SQL, logs query)
4. Mitigation steps (do now)
5. Root cause investigation (check after)
6. Whom to escalate to
```

Create runbook before enabling alert in prod.

## Agent adds itself
- **AI/LLM metrics:** token cost, latency per provider, jailbreak attempts, hallucination eval-failures (see `eval-design`).
- **Privacy-aware logging:** link to `security-design` — no PII in logs, mask email/phone/PAN.
- **Cost guardrails:** alert on observability budget overrun.
- **Multi-tenant** (if present): separate logs + metrics per tenant.

## Output file structure
`docs/03_Dev/Observability_Design.md`:

```markdown
---
project: [ProjectName]
stage: observability-design
based_on: docs/03_Dev/Architecture_[Name].md
tier: 0 | 1 | 2
---

# Observability Design — [ProjectName]

## Tier и бюджет
**Tier:** [0/1/2]
**Месячный бюджет:** $[X]

## Logs
- Формат: JSON, поля: ...
- Уровни и правила использования
- Агрегатор: ...
- Retention: ...
- PII policy: ...

## Metrics
### RED (per endpoint)
- ...

### USE (per resource)
- ...

### Business KPI
- ...

### Domain-specific (AI / payments / etc)
- ...

## Traces
- Стандарт: OpenTelemetry
- Sampling: ...
- Обязательные span'ы: ...
- Propagation: ...

## Alerts
| Имя | Severity | Условие | Runbook |
|:---|:---|:---|:---|
| ... | P1 | ... | `docs/03_Dev/Runbooks/...md` |

## SLO / SLI
| Сервис | SLI | Target | Error budget |
|:---|:---|:---|:---|

## Error budget policy
- ...

## Runbooks
[список созданных файлов в docs/03_Dev/Runbooks/]

## Domain-specific дополнения (агент)
- ...
```

## Hard prohibitions
NEVER:
- Alert without runbook
- Alert without severity
- Tier-2 (Datadog full-stack) on pre-seed without justification
- PII in logs without masking
- "Logs somehow" — structured JSON required
- Metrics without named labels / dimensions
- trace_id not propagated through async / queue — breaks debug

## Related skills
- `architect/architecture` — approved architecture as precondition.
- `architect/security-design` — PII-policy in logs, mask email/phone/PAN.
- `architect/eval-design` — AI/LLM metrics (token cost, hallucination rate) feed prod monitoring (input).
- `operator/runbook` — detailed operational runbook per P1/P2 alert designed here (output).
- `operator/incident-response` — response process relying on severity + alerts here (output).
- `operator/capacity-planning` — what to monitor for scaling, based on USE-metrics here.
- `laravel-testing/health-checks` — Laravel liveness/readiness (spatie/laravel-health) for design here.

<!-- ru-source-sha256: 652348b32c37677c634dd0dba0cc63dc9e1843df2c6f5be60e94b91db1b73eda -->
