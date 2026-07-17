# HANDOFF — 2026-07-17 — after P1.2

**Next:** ⏸ ОЖИДАНИЕ P1.3 (§8, healthy-gate — НЕ §10-эскалация): P1.3 (T2 — семантика
`removeScopedRole(panelId=null)`) заблокирован до продуктового решения владельца — Q1
(`open-questions.md`) НЕ разрешена (нет `D#` в `plan.md`). НЕ запускать P1.3 без него.
Владелец должен выбрать Вариант A/B (см. `plan.md` `## Обсуждение` №1) и записать `D#`;
после этого — `/task:plan-design 2026.07.17-AZGUARD-TAILS P1.3` дозаполняет Code Guidance.

Пока Q1 не разрешена, доступна независимая работа: P2 (T3/T4/T5) — через
`Workflow({scriptPath: "plans/2026.07.17-AZGUARD-TAILS/workflows/wf-azguard-tails-p2.js"})`
(P2.1-P2.4, `Exec=plan-exec`, sonnet/medium). НЕ гнать P2-workflow параллельно ручной P1-сессии
(scope по коду независим, но обе пишут одни и те же файлы бухгалтерии — `plan.md`/`handoff.md` —
двумя коммитами каждая; см. аудит A10) — но раз P1 сейчас тоже стоит (ОЖИДАНИЕ Q1), можно
безопасно запускать P2 сейчас, если пользователь не планирует параллельно вручную трогать P1.

| Параметр | Значение |
|:--|:--|
| P1.3 | Заблокирован — ОЖИДАНИЕ Q1, не начинать без `D#` |
| P2 | Разблокирован — `plan-exec`/sonnet-medium через свой workflow-скрипт |

**Done:** P1.1 (T1) закрыт — panel-aware query-scope guard (D5) + eager-load recursion fix
(D9) в `bootHasScopedRoles()`. Item-commit `c166538`. Статус 🟠 Done with deviations (см.
`phases/P1.md` P1.1 Known Deviations).
P1.2 (T6) закрыт — атомарный epoch bump в `PermissionCache::forgetForUser()`: `add()`→
`increment()`→`put()` сериализован под `Cache::lock()` (`LockProvider`-guard через
`getStore()`+`instanceof`, phpstan level 6 чист; graceful degradation без лока для
кастомных драйверов без `LockProvider`). Новый regression-тест — spy-декоратор над
`Store`/`Lock` (`PermissionCacheLockSpyStore`/`PermissionCacheLockSpy`), доказывает
`lock()` → `block()` → `add`/`increment`/`put` внутри колбэка → release, не физическую
кросс-процессную гонку (за scope, см. Pending Work). CHANGELOG/REMAINDER/IMPROVEMENT
синхронизированы (D6). Item-commit `58ed1c4`. Статус 🟢 Done (без отклонений — memory_limit
OOM тот же известный факт локального окружения, что и в P1.1, не отклонение).
**Remaining:** P1.3 (заблокирован Q1) — по одному, после разрешения владельцем; P2.1-P2.4 —
через `Workflow({scriptPath: "plans/2026.07.17-AZGUARD-TAILS/workflows/wf-azguard-tails-p2.js"})`.
**Sources of truth:** plans/2026.07.17-AZGUARD-TAILS/plan.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P1.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P2.md ·
plans/2026.07.17-AZGUARD-TAILS/roadmap.md · `REMAINDER_REPORT.md` (T1/T6 теперь 🟢, T2-T5 актуальны).
**Open risks:** P1.3 заблокирован продуктовым решением владельца (Q1, `open-questions.md`) —
не начинать без `D#`; P2.3 (T5) может потребовать эскалации, если SQLite не воспроизводит
задокументированный rollback-отказ (см. Escalation Needed P2.3). Голый `composer test`
OOM-ит на этом локальном окружении (`memory_limit=128M`, подтверждено повторно на P1.2) —
P2.1-P2.3 столкнутся с тем же, гонять Validation через
`php -d memory_limit=1G vendor/bin/pest` (эквивалент, не отклонение).
**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: T6 — реальный Redis-интеграционный тест на гонку явно вынесен ЗА scope P1.2
  (array-лок доказывает наличие лок-обёртки, не физическую сериализацию) — если нужен,
  отдельный follow-up.
- open_questions: Q1 (`open-questions.md`) — семантика `removeScopedRole(panelId=null)`,
  блокирует P1.3.
