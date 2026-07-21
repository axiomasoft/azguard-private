---
name: task-brief-template
bucket: general
version: 0.4.0
description: "Brief template for complex agent tasks: goal, scope, invariants, explicit assumptions, out of scope, acceptance criteria, definition of done. Plus Plan→Clear→Execute pattern and loop fields for iterative runs: verifier, exit condition, hard-stop."
risk: draft
persona: oss-dev
tags: [workflow, template, requirements, context]
requires: []
produces_for: [complex-task-orchestrator]
outputs: []
snippets: ["loop-kickoff.md"]
adapters: [claude, cursor, fable]
sha256: ""
---

# Task Brief Template

Activate when the task is ambiguous or spans multiple subsystems.

## Brief template

```text
Контекст:
- Коротко: где проблема/инициатива и почему это важно.

Цель:
- Какой результат должен получить пользователь/бизнес.

Границы:
- Что входит в задачу.

Out of Scope (не трогаем без явного запроса):
- Файлы/модули вне задачи, даже если «можно улучшить».
- Новые зависимости (composer/npm) — только по явному запросу.
- Рефакторинг соседнего кода.

Явные предположения:
- Агент ОБЯЗАН верифицировать каждый пункт до старта;
  при несоответствии — стоп, уточняющий вопрос.
- [ ] Предположение 1: ...
- [ ] Предположение 2: ...

Инварианты:
- Какие правила и контракты нельзя нарушать.

Затронутые слои:
- backend / frontend / policies / routes / docs / tests.

Ожидаемое поведение:
- До изменения:
- После изменения:

Критерии приемки:
- [ ] ...
- [ ] ...

Минимальная верификация:
- Какие тесты/проверки обязательно запустить.

Loop (заполнять, если задача предполагает >1 осмысленной итерации):
- Loop verifier: команда/проверка между кругами (тест, линт, `git log`).
- Exit condition: объективное состояние = задача завершена (по выводу проверки,
  не по утверждению агента).
- Hard stop trigger: сигнал, при котором агент останавливается и запрашивает
  согласование (см. circuit breaker в `anti-drift`).

Definition of Done:
- [ ] Все критерии приемки закрыты.
- [ ] Тесты и линт зелёные.
- [ ] git diff не содержит изменений вне Scope.
- [ ] Документация/CHANGELOG обновлены (если задача меняет публичное поведение).

Ограничения:
- Срок, риски, legacy-ограничения, обратная совместимость.
```

## Brief-quality checklist

- Goal describes the result, not just actions.
- Explicit non-goals (out of scope) + ban on edits outside scope.
- Assumptions written out explicitly, not made silently.
- Domain + security invariants listed.
- Minimal test-gate specified.
- Definition of Done distinct from acceptance criteria: process conditions (tests, lint, diff within scope), not feature behavior.
- Final task-report format fixed.
- For iterative tasks Loop fields filled: loop does not start without Exit condition.

To fill the template from a fuzzy idea use skill `spec-interview` (bucket general): one-question-at-a-time interview before any code.

## Plan → Clear → Execute

For large tasks split brief and execution across sessions:

1. **Plan session**: discuss via template above, save the finished brief/plan to file (`.claude/plans/<task>.md`). Plan is self-contained: the executor will not see the current discussion.
2. `/clear` — intermediate planning attempts must not bleed into execution context.
3. **Execution session**: load only the plan file and execute step by step.

Ready `/plan` and `/execute` command templates + rest of context-saving discipline — in skill `context-economy` (bucket general).

## Iterative (loop) execution

If the task needs several rounds with intermediate checks — fill the Loop fields in the template and run via built-in `/loop` (self-paced) or `/schedule` (cron). Round stop-controller — `anti-drift` (circuit breaker); between-rounds verifier — `cross-layer-change-checklist`. Ready starter template — `snippets/loop-kickoff.md` (copy into project `.claude/commands/`). Full model and why there is no separate `loops/` registry — guide [Loop semantics](https://github.com/academici/swissknifeman/blob/main/docs/guide/loop-semantics.md).

## Related skills

- `spec-interview` (general) — fill this template from a fuzzy idea via interview.
- `complex-task-orchestrator` (general) — decompose a finished brief into per-layer subtasks.
- `plan-protocol` (general) — multi-phase work longer than one session is a master plan
  in `plans/<ID>/`; this brief is for a single task within one session.
- `cross-layer-change-checklist` (general) — sync layers during execution.
- `context-economy` (general) — Plan→Clear→Execute and `/plan`, `/execute` commands.
- `anti-drift` (general) — iteration discipline, relies on fixed scope.

<!-- ru-source-sha256: ec328ef1ad906f5d528b45b57a61b597ed600afcbca41e0f4d00c9cf7fc5c0b7 -->
