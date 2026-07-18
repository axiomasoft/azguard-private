# HANDOFF — 2026-07-18 — after P2.1

**Next:** P2.2 (T4 — literal `*` не матчит dynamic-плейсхолдер при wildcard-off). `Exec:
plan-exec`, sonnet/medium (§3 Routing).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium |
| Context | continue (/clear) — ручной item |
| Суть | P2.2 — правка `filterAgainstCatalog()` wildcard-off ветки + regression-тест |

```
/task:plan-exec 2026.07.17-AZGUARD-TAILS P2.2
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
P2.1 (T3) закрыт — `Log::warning("AzGuard: enum class [{$enumClass}] does not exist,
skipping catalog entry.", ['panel' => $panelId])` добавлен в
`EnumPermissionCatalogBuilder::build()`, паритетно `PolicyAbilityCatalogBuilder`; новый
`tests/Unit/Registry/EnumPermissionCatalogBuilderTest.php` (2 теста, `Log::spy()` +
`Log::shouldHaveReceived`). `CHANGELOG.md`/`REMAINDER_REPORT.md`/`IMPROVEMENT_PLAN.md`
обновлены в item-commit. Item-commit `b1de1ac`. Статус 🟢 Done.

**Remaining:** P2.2, P2.3 (P2.4/T7 уже закрыт решением D3, экзекуции не требует). Фаза P1
не закрыта формально (`## Phase Handoff` в `phases/P1.md` не заполнен) — items все
закрыты, но phase-close (`/task:plan-close`) ещё не проведён. Оркестрация P2 через
workflow (D4, `wf-azguard-tails-p2.js`) была задекларирована на этапе дизайна, но P2.1
исполнен напрямую через `/task:plan-exec` по прямому указанию запускающей стороны —
process-отклонение, не влияющее на статус item'а (см. `phases/P2.md` P2.1 Known
Deviations — про workflow там не сказано отдельно, т.к. само по себе использование
`plan-exec` вместо `Workflow(...)` не входит в enum material-отклонений §6; отмечено
здесь для прозрачности следующему шагу).

**Sources of truth:** plans/2026.07.17-AZGUARD-TAILS/plan.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P1.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P2.md ·
plans/2026.07.17-AZGUARD-TAILS/roadmap.md · `REMAINDER_REPORT.md` (T1/T2/T3/T6 — 🟢,
T4/T5 актуальны).

**Open risks:** P2.3 (T5) может потребовать эскалации, если SQLite не воспроизводит
задокументированный rollback-отказ (см. Escalation Needed P2.3). Голый `composer test`/
`vendor/bin/phpstan analyse` OOM-ят на этом локальном окружении (`memory_limit=128M`,
подтверждено повторно на P1.1/P1.2/P1.3/P2.1) — гонять Validation через
`php -d memory_limit=1G vendor/bin/pest` / `php -d memory_limit=1G vendor/bin/phpstan
analyse --memory-limit=1G` (эквивалент, не отклонение).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: T6 — реальный Redis-интеграционный тест на гонку явно вынесен ЗА scope P1.2
  (array-лок доказывает наличие лок-обёртки, не физическую сериализацию) — если нужен,
  отдельный follow-up.
- open_questions: — (Q1 разрешена, D10, Вариант B, реализована в P1.3; никаких открытых
  вопросов не осталось).
