---
id: D4
date: 2026-09-22
status: accepted
item: P1
items: [P1, P2, P3, P4, P5, P6, P7]
supersedes: []
superseded_by: null
---
# D4 — Весь дизайн и общий аудит до исполнения

**Actor:** repository owner / plan-designer Codex root
**Evidence:** RAG:— owner clarification 2026-09-22; текущий handoff после P1 ошибочно выбрал `plan-exec P1.1`, пока P2–P7 оставались скелетами; swissknifeman roadmap `2026.09.17-task-final-plan-audit-and-route-coalescing.md` описывает целевую, но ещё не внедрённую модель.

## Решение

Последовательность PLAN1 обязательна: сначала детализировать все фазы P1–P7, затем выполнить
`task:plan-design 2026.09.22-№1-AZGUARD-CORRECTNESS finish`, после него один общий
`task:plan-audit 2026.09.22-№1-AZGUARD-CORRECTNESS design`, исправить его material findings
через owning design items и получить GREEN plan-wide verdict. Только после этого handoff может
указывать `plan-exec`/`plan-run`.

D3 остаётся действующим для глубины review внутри P1: никакого трёхстадийного item/phase review.
Общий design audit выполняется один раз над полностью детализированным планом и не является
phase audit или повторным post-review каждого item.

## Почему

Локально готовая ранняя фаза не доказывает согласованность зависимостей, публичных контрактов,
Routing и validation всего плана. Действующий Task runtime этого гейта не обеспечивает и после
P1 предложил преждевременное исполнение; owner подтвердил, что это нарушение принятого процесса.
Один plan-wide audit после полного дизайна даёт нужную проверку без возврата к избыточному
трёхстадийному review для каждого item.

## Consequences

Handoff после P1 ведёт в design P2. Аналогично P2–P6 ведут к следующей недетализированной фазе;
P7 ведёт в `design-finish`, а finish — в `plan-audit design`. Execution bundles могут быть
сгенерированы заранее, но не являются разрешением запуска. Пробел Task runtime записан в
`swissknifeman:roadmap/2026.09.17-task-final-plan-audit-and-route-coalescing.md`.
