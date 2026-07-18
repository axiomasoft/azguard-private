# HANDOFF — 2026-07-18 — after P1.3

**Next:** P1 фаза целиком закрыта по items (P1.1/P1.2/P1.3 все 🟢/🟠 Done). Осталось P2
(T3/T4/T5) — задекларирована оркестрация (D4), workflow-скрипт готов. Первый шаг:

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium |
| Context | new session |
| Суть | P2.1-P2.4 через workflow (T3/T4/T5 — диагностика/wildcard/rollback; T7 уже закрыт решением) |

```
Workflow({scriptPath: "plans/2026.07.17-AZGUARD-TAILS/workflows/wf-azguard-tails-p2.js"})
```

**Done:** P1.1 (T1) закрыт — panel-aware query-scope guard (D5) + eager-load recursion fix
(D9) в `bootHasScopedRoles()`. Item-commit `c166538`. Статус 🟠 Done with deviations (см.
`phases/P1.md` P1.1 Known Deviations).
P1.2 (T6) закрыт — атомарный epoch bump в `PermissionCache::forgetForUser()`: `add()`→
`increment()`→`put()` сериализован под `Cache::lock()` (`LockProvider`-guard через
`getStore()`+`instanceof`, phpstan level 6 чист; graceful degradation без лока для
кастомных драйверов без `LockProvider`). Item-commit `58ed1c4`. Статус 🟢 Done.
P1.3 (T2) закрыт — Вариант B (D10): `removeScopedRole(panelId=null)` теперь удаляет
только any-panel строку, новый метод `removeScopedRoleEverywhere()` воспроизводит старое
поведение «снести везде». `### Breaking` запись в `packages/core/CHANGELOG.md`, заметка в
`docs/ru/advanced/entity-scopes.md`. Item-commit `b972162`. Статус 🟢 Done.
**Remaining:** P2.1-P2.4 — через
`Workflow({scriptPath: "plans/2026.07.17-AZGUARD-TAILS/workflows/wf-azguard-tails-p2.js"})`.
Фаза P1 не закрыта формально (`## Phase Handoff` в `phases/P1.md` не заполнен) — items
все закрыты, но phase-close (`/task:plan-close`) ещё не проведён.
**Sources of truth:** plans/2026.07.17-AZGUARD-TAILS/plan.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P1.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P2.md ·
plans/2026.07.17-AZGUARD-TAILS/roadmap.md · `REMAINDER_REPORT.md` (T1/T2/T6 — 🟢, T3-T5 актуальны).
**Open risks:** P2.3 (T5) может потребовать эскалации, если SQLite не воспроизводит
задокументированный rollback-отказ (см. Escalation Needed P2.3). Голый `composer test`
OOM-ит на этом локальном окружении (`memory_limit=128M`, подтверждено повторно на
P1.1/P1.2/P1.3) — гонять Validation через `php -d memory_limit=1G vendor/bin/pest`
(эквивалент, не отклонение).
**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: T6 — реальный Redis-интеграционный тест на гонку явно вынесен ЗА scope P1.2
  (array-лок доказывает наличие лок-обёртки, не физическую сериализацию) — если нужен,
  отдельный follow-up.
- open_questions: — (Q1 разрешена, D10, Вариант B, реализована в P1.3; никаких открытых
  вопросов не осталось).
