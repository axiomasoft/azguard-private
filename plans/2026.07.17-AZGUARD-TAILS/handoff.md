# HANDOFF — 2026-07-18 — after P2.3

**Next:** все items фазы P2 терминальны (P2.1/P2.2/P2.3 🟢, P2.4 ⛔ Skipped by decision) —
закрыть фазу.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P2 (`plan-close`) — сверка Phase Status/Phase Handoff по факту |

```
/task:plan-close 2026.07.17-AZGUARD-TAILS P2
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
P2.2 (T4) закрыт — `filterAgainstCatalog()` wildcard-off ветка теперь дропает ключи с `*`
до dynamic-сопоставления (`str_contains($key, PermissionKey::WILDCARD)`-гвард, зеркалит
wildcard-ON ветку строки 143), паритетно wildcard-ON. Два новых теста (позитив+негатив) в
`tests/Unit/Registry/EffectivePermissionResolverTest.php`. Item-commit `6bead71`. Статус
🟢 Done.
P2.3 (T5) закрыт — Feature-тест `tests/Feature/ScopeClassMigrationRollbackTest.php`
экспериментально подтвердил (throwaway-прогон, не копия докблока): на SQLite (тестовая БД
проекта) `down()` миграции 000004 падает `Illuminate\Database\QueryException` («NOT NULL
constraint failed») при существующей null-строке `scope_class` — то же документированное
поведение, что и для MySQL/PostgreSQL, докблок не потребовал правки. Второй тест —
негативный контроль (без null-строк `down()` не бросает). `down()` не изменён (Scope
Excluded соблюдён, эскалация не потребовалась). `REMAINDER_REPORT.md`/
`IMPROVEMENT_PLAN.md` (T5 → 🟢) обновлены в item-commit. Item-commit `f75e0ef`. Статус 🟢
Done.

**Remaining:** экзекуции не осталось — P1 (P1.1-P1.3 все 🟢/🟠) и P2 (P2.1-P2.3 🟢,
P2.4 ⛔) полностью терминальны по items, но ОБЕ фазы не закрыты формально (`## Phase
Handoff` в `phases/P1.md` и `phases/P2.md` не заполнен, `plan-close` ещё не проведён).
Оркестрация P2 через workflow (D4, `wf-azguard-tails-p2.js`) была задекларирована на
этапе дизайна, но все три item'а P2 исполнены напрямую через `/task:plan-exec`/ручной
запуск по прямому указанию запускающей стороны в каждой сессии — process-отклонение, не
влияющее на статус items (не входит в enum material-отклонений §6).

**Sources of truth:** plans/2026.07.17-AZGUARD-TAILS/plan.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P1.md ·
plans/2026.07.17-AZGUARD-TAILS/phases/P2.md ·
plans/2026.07.17-AZGUARD-TAILS/roadmap.md · `REMAINDER_REPORT.md` (T1-T6 — 🟢, T7 — ⛔).

**Open risks:** Голый `composer test`/`vendor/bin/phpstan analyse` OOM-ят на этом
локальном окружении (`memory_limit=128M`, подтверждено повторно на всех items
P1.1-P1.3/P2.1-P2.3) — гонять Validation через `php -d memory_limit=1G vendor/bin/pest` /
`php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G` (эквивалент, не
отклонение). Обе фазы (P1/P2) требуют формального `plan-close` перед тем, как план в
целом можно будет считать готовым к архивации.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: T6 — реальный Redis-интеграционный тест на гонку явно вынесен ЗА scope P1.2
  (array-лок доказывает наличие лок-обёртки, не физическую сериализацию) — если нужен,
  отдельный follow-up.
- open_questions: — (Q1 разрешена, D10, Вариант B, реализована в P1.3; никаких открытых
  вопросов не осталось).
