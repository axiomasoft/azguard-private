# HANDOFF — 2026-07-18 — after P1

**Next:** план закрыт.

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
P2.1 (T3) закрыт — `Log::warning(...)` паритет в `EnumPermissionCatalogBuilder::build()`.
Item-commit `b1de1ac`. Статус 🟢 Done.
P2.2 (T4) закрыт — `filterAgainstCatalog()` wildcard-off ветка дропает ключи с `*` до
dynamic-сопоставления, паритетно wildcard-ON. Item-commit `6bead71`. Статус 🟢 Done.
P2.3 (T5) закрыт — rollback-тест миграции 000004 подтвердил докблок экспериментально,
`down()` не изменён. Item-commit `f75e0ef`. Статус 🟢 Done.

**Фаза P1 закрыта** (🟠 Done with deviations) — все items терминальны (P1.1 🟠, P1.2/P1.3
🟢), docs-sync не требуется (доки уже обновлены в item-коммитах P1.1/P1.3), известные
отклонения — механически собраны в `phases/P1.md` Phase Handoff (OOM голого
`composer test` на локальном окружении, `IMPROVEMENT_PLAN.md` без колонки `Status`,
временный CHANGELOG.md permission-обход).
**Фаза P2 закрыта** (🟢 Done) — все items терминальны (P2.1-P2.3 🟢, P2.4 ⛔ Skipped by
decision).

Обе фазы плана терминальны → **план `2026.07.17-AZGUARD-TAILS` закрыт целиком**. Все семь
хвостов T1-T7 из `REMAINDER_REPORT.md` закрыты (T1-T6 — 🟢/🟠, T7 — ⛔ Skipped by decision,
D3).

**Remaining:** —

**Sources of truth:** plans/2026.07.17-AZGUARD-TAILS/plan.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P1.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P2.md ·
plans/2026.07.17-AZGUARD-TAILS/roadmap.md · `REMAINDER_REPORT.md` (T1-T6 — 🟢, T7 — ⛔).

**Open risks:** Голый `composer test`/`vendor/bin/phpstan analyse` OOM-ят на этом
локальном окружении (`memory_limit=128M`) — гонять Validation через
`php -d memory_limit=1G vendor/bin/pest` / `php -d memory_limit=1G vendor/bin/phpstan
analyse --memory-limit=1G` (эквивалент, не отклонение).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: T6 — реальный Redis-интеграционный тест на гонку явно вынесен ЗА scope P1.2
  (array-лок доказывает наличие лок-обёртки, не физическую сериализацию) — если нужен,
  отдельный follow-up.
- open_questions: — (Q1 разрешена, D10, Вариант B, реализована в P1.3; никаких открытых
  вопросов не осталось).

## Migration checklist (archive)

- `root/` в плане отсутствовал — миграция в `docs/` не требовалась, шаг пропущен.
- Остаётся в архиве целиком: `plan.md`, `phases/P1.md`, `phases/P2.md`, `roadmap.md`,
  `open-questions.md`, `brief/`, `workflows/wf-azguard-tails-p2.js`, этот `handoff.md`.
- `plans/ACTIVE.md` уже указывал `—` (план не был активным на момент архивации).
- Внешних ссылок на `plans/2026.07.17-AZGUARD-TAILS` в живых доках/реестрах не найдено
  (`grep -rn` по репо — пусто).
