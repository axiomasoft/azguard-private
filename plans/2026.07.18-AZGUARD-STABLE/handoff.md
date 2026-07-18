# HANDOFF — 2026-07-18 — after P3.1

**Next:** P3.1 закрыт (🟠 Done with deviations) — cut-line фасада исполнен по
`root/contracts/facade-cutline.md`/D29. Следующий item — **P3.2** (snapshot-гейт
заморозки поверхности): предписанный Routing — `fable/high manual` (D28 не
трогает P3.2 — единственный оставшийся fable-item фазы, механизм заморозки
несёт необратимую цену ошибки).

| Параметр | Значение |
|:--|:--|
| Model | fable |
| Thinking | high — предписано §3 Routing/D28 (снапшот-гейт: механизм заморозки поверхности, ошибка = тихий дрейф @api) |
| Context | continue (/clear) — ручной item |
| Суть | P3.2: расширить `tests/Unit/ApiBoundaryTest.php` (или sibling) до snapshot-гейта — закоммиченный фикстур `@api`-поверхности core (типы+сигнатуры+имена параметров) из `root/api-surface.md` (P3.1), самопроверка «мутация→red» |

```
/task:plan-exec 2026.07.18-AZGUARD-STABLE P3.2
```

**Done:** P3.1 (Исполнение cut-line фасада + ревизия @api/@internal →
root/api-surface.md) закрыт. Cut-line по `root/contracts/facade-cutline.md`
(P2.5, D29) — НЕ по устаревшей букве Scope Included этого item'а (которая
предшествовала D29 и всё ещё несёт опровергнутую посылку «0 потребителей»):
2 `@method`-строки (`tryPermission`, `panelIdForPermission`) убраны из
докблока фасада `AzGuard.php`; сами методы на `AzGuardManager`/
`AzGuardManagerInterface` СОХРАНЕНЫ и помечены `@internal` (реальные швы —
`Permissions/PermissionName.php:31`, `Concerns/HasScopedRoles.php:324`);
`isSuperAdmin` перенесён в `@internal`-секцию докблока рядом с `grant`/
`revoke`/`grants` (уже проставлены P2.3); `hasContextGuard` остался `@api` с
локус-нотой. Сквозная ревизия P2.1-переездов (Panels/Permissions/
Configuration/Runtime/Abilities/Auth/Database\Schema) не выявила ни одной
потери `@api`-тега. `root/api-surface.md` создан — реестр факта: 32
`@api`-типа core (18 Contracts/ + 6 Registry/Contracts/ + Facade + Panel +
PermissionKey + PermissionSet + 4 Testing), финальный `@method`-состав
фасада, middleware `::using()`, Blade-директивы, config-ключи core+filament;
явно называет вне-скоупа (context/filament без конвенции тегирования,
`Attributes/*` untagged — пред-P2.1 гэп).

Follow-up находки `## Audit P2 — 2026-07-18` (phases/P2.md) свёрнуты этой же
сессией (НЕ реопен P2): **F1** — `composer refactor` прогнан по всем 6
файлам; попутно обнаружен и устранён скрытый конфликт rector↔pint (докблок
`{@see Class}`, где `Class` используется ТОЛЬКО в PHPDoc — rector считает
импорт мёртвым, pint хочет его вернуть; переписано прозой без `{@see}` — оба
гейта теперь сходятся к нулю ОДНОВРЕМЕННО); формулировка «предсуществующий/не
задет» была ложной и теперь неактуальна (долг устранён, не просто
переквалифицирован). **F3/F4/F6** — `roadmap.md` P3.1/P3.3 ресинхронизированы
под D28 (sonnet/high); `plan.md:168` Update Log сокращён (458→~150 символов);
D28/D29 переставлены в хронологический порядок. **F2** — сознательно НЕ
исполнен здесь (по прямому указанию launch-команды: адрес — P3.3); зафиксирован
в P3.1 Pending Work и продублирован в Required Reads P3.3, чтобы не потеряться.

**Remaining:** P3.2 (snapshot-гейт, fable/high) → P3.3 (SemVer-политика +
UPGRADING, sonnet/high — несёт F2 pending) → Phase Handoff P3 → штатно P4
(тест-углубление) → P5 (шаблон → релиз+тег → миграция root/→docs) → post-plan
`/task:plan-close archive 2026.07.18-AZGUARD-STABLE`.

**Sources of truth:** plans/2026.07.18-AZGUARD-STABLE/plan.md (v0.3.17, D1–D29,
§4 Phase Index P3=🟡 In progress 1/3) · phases/P3.md (P3.1 Completion Notes —
полная сверка cut-line с фактом кода) · phases/P2.md `## Audit P2 —
2026-07-18` (F1–F7, здесь свёрнуты F1/F3/F4/F6; F2 передан P3.3) ·
root/contracts/facade-cutline.md (замороженная спека, D29 — исполнена) ·
root/api-surface.md (НОВЫЙ — SSOT входа P3.2/P3.3) · roadmap.md (P3.1/P3.3
ресинхронизированы под D28).

**Open risks:**
- F2 (Audit P2) не исполнен — P3.3 обязан внести в UPGRADING оба пропущенных
  breaking (P2.4 `panel_check` арг-флип, P2.1 переезды публичных типов);
  Required Reads P3.3 уже указывает на это явно (см. phases/P3.md).
- `Attributes/*` (`CheckPermission`, `GuardPolicy`, `GateAbility`,
  `SkipGuardCheck`, `RoleOnly`) документированы, но не несут `@api`-тег —
  пред-P2.1 гэп тегирования, вне скоупа cut-line; кандидат отдельного item'а
  (root/api-surface.md §8).
- Регенерация bundled boost-скилла (`packages/core/resources/boost/skills/
  azguard-development/SKILL.md`) целиком — эта сессия поправила ТОЛЬКО одну
  устаревшую строку примера (`isSuperAdmin`); полный прогон
  `laravel-package-generate-skill` после cut-line остаётся кандидатом
  (facade-cutline.md §5, P2.5 Pending Work).
- `tests/Unit/Support/` (5 файлов) — имя каталога дрейфует от канона (P2.1
  Pending Work, не тронуто).
- Прямой unit-тест `SimplePermissionDefinition::label()` и уточнение generics
  `Contracts\HasRoles::roles()` (P2.2 Pending Work) — вне Files всех items.
- Удаление legacy `WildcardPermissionMatcher` + флага — следующий
  deprecate-цикл ПОСЛЕ 0.3.0 (P2.9 Pending Work, кандидат
  known-limitations/semver-policy P3.3).
- MySQL-ветка миграции 000005 / миграция 000011 (expires_at) не гонялись на
  MySQL локально — верификация в P4.2/P4.7.
- `removeScopedRoleEverywhere()` вне контракта + Policy-авторизация Filament
  RoleResource — кандидаты P2 contract review, НЕ делать без D#.
- P5.2 push тега — необратимая внешняя операция: гейт владельца обязателен.
- `plan-lint.py` вызывается по абсолютному пути (swissknifeman/packages/task/
  scripts/, `${CLAUDE_PLUGIN_ROOT}` пуст в среде).
- `AzGuard::fake()` + глобальный `Event::fake()`: подавляет реальную доставку
  слушателей (doc-note уже в advanced/testing.md).

**Workarounds/Deferred/Open questions:**
- workarounds: `plan-lint.py` по абсолютному пути; докблок `{@see Class}` на
  импорт, используемый ТОЛЬКО в PHPDoc, переписывается прозой (rector↔pint
  конфликт — F1 remediation, см. P3.1 Completion Notes).
- deferred: F2 (Audit P2) → P3.3; регенерация bundled boost-скилла целиком →
  кандидат; `Attributes/*` тегирование → кандидат; удаление legacy-matcher →
  post-0.3.0 (P2.9); RoleResource Livewire-тест (P1.2); split/Packagist
  (D25); roave/bc-check (D20); снапшот filament/context (P3.2 Scope Excluded,
  каталогизировать P3.3); `removeScopedRoleEverywhere()` → контракт (P2);
  rename `tests/Unit/Support/` → отдельный item.
- open_questions: Q1→D22, Q2→D23/D24, Q3→D27. Открытых нет.
