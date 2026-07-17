# Roadmap исполнения — 2026.07.17-AZGUARD-TAILS

**Обновлён:** 2026-07-17 · **Соответствует plan.md:** v0.5.0

## Карта исполнения

| Item | Batch | Запуск | Model/Thinking | Гейт владельца | Примечание |
|---|---|---|---|---|---|
| P1.1 | solo | manual (plan-run) | sonnet/high | — | T1, panel-isolation correctness + co-located фикс рекурсии eager-load (D9); тест на реальном fetch-пути с обходом console-guard; effort high MANDATORY, объединять с P1.2/P1.3 нельзя (§9 «result > economy») |
| P1.2 | solo | manual (plan-run) | sonnet/high | — | T6, concurrency correctness; effort high MANDATORY |
| P1.3 | solo | manual (plan-run), ЖДЁТ Q1 | sonnet/medium | ✅ Q1 (`open-questions.md`) — семантика `removeScopedRole(panelId=null)` | Не запускать до `D#`, разрешающего Q1; после разрешения — `/task:plan-design 2026.07.17-AZGUARD-TAILS P1.3` для дозаполнения Code Guidance |
| P2.1–P2.3 | W1 | workflow | sonnet/medium | — | независимый scope + детерминированная Validation → `wf-azguard-tails-p2.js` |
| P2.4 | — | закрыт (D3) | — | — | ⛔ Skipped by decision, экзекуции не было и не будет |

## Готовые launch-block'и групп

### P1.1 (solo, manual)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — panel-isolation correctness, invariant-класс (SKILL §9 thinking policy) |
| Context | continue (/clear) — ручной item |
| Суть | Panel-aware фильтрация в `HasScopedRoles::bootHasScopedRoles()` (T1) |

```
ЗАПУСК ВРУЧНУЮ: sonnet/high — P1.1 (§3 Routing: Exec=manual; /task:plan-exec пинит
sonnet/medium и по routing-гейту §9 обязан отказать)

Прочитать: plans/2026.07.17-AZGUARD-TAILS/plan.md →
plans/2026.07.17-AZGUARD-TAILS/phases/P1.md (item P1.1, все 16 полей) → файлы из
Required Reads P1.1. Реализовать по Code Guidance P1.1. Закрыть по plan-protocol §8
(item commit по Files → bookkeeping commit plans/**).
```

### P1.2 (solo, manual)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — concurrency correctness, invariant-класс |
| Context | continue (/clear) — ручной item |
| Суть | Атомарный epoch-bump в `PermissionCache::forgetForUser()` (T6) |

```
ЗАПУСК ВРУЧНУЮ: sonnet/high — P1.2 (§3 Routing: Exec=manual; /task:plan-exec пинит
sonnet/medium и по routing-гейту §9 обязан отказать)

Прочитать: plans/2026.07.17-AZGUARD-TAILS/plan.md →
plans/2026.07.17-AZGUARD-TAILS/phases/P1.md (item P1.2, все 16 полей) → файлы из
Required Reads P1.2. Реализовать по Code Guidance P1.2. Закрыть по plan-protocol §8.
```

### P1.3 (solo, manual, ОЖИДАНИЕ Q1)

```
ОЖИДАНИЕ: D# в plan.md разрешает open-questions.md Q1 →
ЗАПУСК ВРУЧНУЮ: sonnet/medium — P1.3
```

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium |
| Context | continue (/clear) — ручной item |
| Суть | Реализовать выбранную владельцем семантику `removeScopedRole` (T2) — целевой шаг, ПОСЛЕ Q1 |

```
# НЕ раньше: open-questions.md Q1 разрешён владельцем (D# в plan.md ## 5. Decision Log)
/task:plan-design 2026.07.17-AZGUARD-TAILS P1.3
```

### W1 — P2.1-P2.3 (workflow, независимый scope)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium |
| Context | NEW SESSION — Workflow-оркестрован |
| Суть | T3 (диагностика-паритет) + T4 (wildcard-off guard) + T5 (rollback-тест) — автономная цепочка, скрипт готов |

```
Workflow({scriptPath: "plans/2026.07.17-AZGUARD-TAILS/workflows/wf-azguard-tails-p2.js"})
```

## Гейты владельца (сводно)

| Где | Что утверждает | Блокирует |
|---|---|---|
| `open-questions.md` Q1 | Семантика `removeScopedRole(panelId=null)` — Вариант A или B | P1.3 (не блокирует P1.1/P1.2/W1 — независимый scope) |
