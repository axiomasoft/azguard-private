# Cut-line target-спека фасада `AzGuard` — вход P3 (P2.5)

> **Статус:** замороженный дизайн-артефакт (вход P3.1 «исполнение cut-line» и P3.2
> «snapshot-гейт»). Производитель — P2.5 (fable/high, D28). Изменение вердиктов после
> заморозки P3.2 — только через D-запись.
>
> **Что это:** целевое состояние публичной поверхности фасада
> `packages/core/src/Facades/AzGuard.php` (и парного контракта
> `packages/core/src/Contracts/AzGuardManagerInterface.php`) после выреза P3.1.
> Спека фиксирует ТОЛЬКО target state; порядок/механика выреза — решение P3.1.
>
> **Базис:** классификация C-B4 (findings/P0-axis-b-fluent.md) + канон локуса RAG:✅
> (findings/P0-rag-fluent-dx.md Запрос 2: у spatie фасада нет — центр API это трейт
> User + модели + Gate; фасад azguard мерится как УЗКИЙ оркестровый вход) +
> **сверка с фактом кода** на дереве `9190e8d` (после P2.1–P2.4, P2.6 — см. §3:
> два вердикта C-B4 скорректированы по новым потребителям).

## 1. Таблица вердиктов — 17 @method фасада (полный состав C-B4)

Счётчики docs-исп. — EN-страницы (`grep -roP 'AzGuard::\w+' docs --include='*.md'`,
без `docs/ru/` — зеркала не двоят счёт), на дереве `9190e8d`.

| # | @method | Вердикт | Обоснование | SemVer-эффект |
|:--|:--|:--|:--|:--|
| 1 | `registerPanel` | **оставить @api** | оркестровый вход (панели): SP/PanelProvider-путь, 3 внутренних вызова в src | нет |
| 2 | `getPanels` | **оставить @api** | оркестровая интроспекция: CLI/doctor, 4 внутренних вызова | нет |
| 3 | `panel` | **оставить @api** | оркестровый lookup: middleware, 5 внутренних вызовов | нет |
| 4 | `currentPanel` | **оставить @api** | request-state: CheckAccess и ещё 5 внутренних вызовов | нет |
| 5 | `setCurrentPanel` | **оставить @api** | request-state: SetCurrentPanel, 4 внутренних вызова | нет |
| 6 | `permission` | **оставить @api** | главный docs-резолвер ключа: 16 EN docs-исп. | нет |
| 7 | `tryPermission` | **удалить @method-строку фасада; метод interface/manager → `@internal`** | 0 docs-исп.; НО premise C-B4 «0 внутренних потребителей» устарел — `Permissions/PermissionName.php:31` зовёт через `AzGuardManagerInterface` (шов всех grant-путей); см. §3 | @method уходит из @api-поверхности фасада; interface-метод @api→@internal; runtime сохранён (сигнатуры не меняются) |
| 8 | `panelIdForPermission` | **удалить @method-строку фасада; метод interface/manager → `@internal`** | 0 docs-исп.; внутренний потребитель появился ПОСЛЕ аудита — `Concerns/HasScopedRoles.php:324` (P1-W1 `ed64c93`, B-04); см. §3 | как #7 |
| 9 | `registerGrantSource` | **оставить @api** | оркестровый extension-вход: 3 EN docs-исп. | нет |
| 10 | `registerCatalogBuilder` | **оставить @api** | оркестровый extension-вход: 1 EN docs-исп. | нет |
| 11 | `isSuperAdmin` | **`@internal`** | предикат-дубль трейта (`Concerns/HasPermissions.php`); канонический локус — `$user->isSuperAdmin()`; 0 EN docs-исп. фасадной формы | @api→@internal (де-публикация); runtime сохранён |
| 12 | `abilitiesFor` | **оставить @api** | оркестровая frontend-проекция (без user-объекта на фронте): 6 EN docs-исп. | нет |
| 13 | `hasContextGuard` | **оставить @api + задокументировать локус** | container-level предикат, полезен БЕЗ user (в отличие от дубля в трейте): docs/recipes/integration.md различает `$user->hasContextGuard()` (per-user) и `AzGuard::hasContextGuard()` (container-level) | нет (только docblock-уточнение локуса) |
| 14 | `forUser` | **оставить @api** | ядро fluent-грамматики (D16, P2.3): 24 EN docs-исп. | нет |
| 15 | `grant` | **`@internal` — УЖЕ ИСПОЛНЕНО P2.3** | позиционный дубль fluent-корня; @internal стоит в `AzGuardManagerInterface:130` + прозе докблока фасада («Grants API») | исчерпан P2.3 (коммит `278cdfc`); P3.1 ничего не делает |
| 16 | `revoke` | **`@internal` — УЖЕ ИСПОЛНЕНО P2.3** | как #15 (`AzGuardManagerInterface:145`) | исчерпан P2.3; P3.1 ничего не делает |
| 17 | `grants` | **`@internal` — УЖЕ ИСПОЛНЕНО P2.3** | как #15 (`AzGuardManagerInterface:159`) | исчерпан P2.3; P3.1 ничего не делает |

**Согласованность с P2.3 (обязательная отметка по ТЗ):** вердикты #15–#17
подтверждают уже исполненное P2.3 решение shorthands→@internal (D16); cut-line их
НЕ переоткрывает. Прямые предикаты (`can`/`isSuperAdmin`) в fluent-цепочки не
превращаются (инвариант ARCHITECT_REVIEW §6) — «@internal» для #11 означает
де-публикацию фасадного дубля, канонический путь остаётся на трейте.

## 2. Пост-recon поверхность (не входит в 17 C-B4) — вердикты для полноты заморозки

Добавлена ПОСЛЕ аудита P0 items P2.6 (D14); P3.2 замораживает фасад целиком, поэтому
спека обязана назвать и её:

| @method / метод | Вердикт | Обоснование |
|:--|:--|:--|
| `fake()` (реальный static-метод) | **оставить @api** | Testing DX P2.6, канон Pdf::fake (RAG:✅ Запрос 3); 3 EN docs-исп. |
| `assertGranted` | **оставить @api** | ассерция Recorder'а (P2.6); 2 EN docs-исп. |
| `assertDenied` | **оставить @api** | ассерция Recorder'а (P2.6) |
| `assertChecked` | **оставить @api** | ассерция Recorder'а (P2.6); 2 EN docs-исп. |

## 3. Расхождения с C-B4 — сверка вердиктов по факту кода (дерево `9190e8d`)

C-B4/B-05 квалифицировали `tryPermission`/`panelIdForPermission` как «0 docs- И 0
внутренних потребителей» → канон §5 предписывал «удалить». Факт кода на `9190e8d`:

1. `tryPermission` — внутренний потребитель `packages/core/src/Permissions/PermissionName.php:31`
   (вызов через `app(AzGuardManagerInterface::class)`, НЕ через фасад). Вошёл коммитом
   `3e9adb1` (1.0-finalization, ДО аудита P0) — аудит мерил только фасадную форму
   `AzGuard::\w+`, interface-вызов не попал в счёт. `PermissionName::resolve()` — шов
   ВСЕХ grant-путей (GrantBuilder, HasDirectGrants, HasPermissions, ContextGrantBuilder).
2. `panelIdForPermission` — внутренний потребитель
   `packages/core/src/Concerns/HasScopedRoles.php:324`. Появился ПОСЛЕ аудита:
   P1-W1 коммит `ed64c93` (B-04, панель-деривация из enum-permission).

**Следствие (скорректированный вердикт):** удалить методы `AzGuardManager`/
`AzGuardManagerInterface` НЕЛЬЗЯ (сломаются оба шва). Цель cut-line — сужение
ПУБЛИЧНОЙ поверхности — достигается эквивалентно: @method-строки уходят из докблока
фасада (фасадная @api-поверхность сужается 17→15), методы interface/manager остаются
с `@internal`-докблоком (внутренние швы легальны, паттерн shorthands P2.3).
Формула D19 «P3.1 удаляет 2 мёртвых метода AzGuardManager» уточняется этой спекой:
удаляются 2 @method-СТРОКИ фасада; методы менеджера/интерфейса живут как @internal.
Спека — SSOT для P3.1 (D19: исполнение «по замороженной спеке P2.5»).

## 4. Целевое состояние докблока фасада после P3.1 (target state, не механика)

- **@api @method (11):** `registerPanel` · `getPanels` · `panel` · `currentPanel` ·
  `setCurrentPanel` · `permission` · `registerGrantSource` · `registerCatalogBuilder` ·
  `abilitiesFor` · `hasContextGuard` (с локус-нотой container-level vs `$user->…`) ·
  `forUser`.
- **@api Testing (3 @method + 1 метод):** `assertGranted` · `assertDenied` ·
  `assertChecked` · `fake()`.
- **@internal-секция докблока (4):** `grant` · `revoke` · `grants` (уже исполнено
  P2.3) + `isSuperAdmin` (переносится из «Actor API» с нотой «локус — трейт»).
- **Удаляются из докблока (2):** `tryPermission` · `panelIdForPermission`;
  в `AzGuardManagerInterface`/`AzGuardManager` оба метода получают `@internal`.
- Итог фасадной @api-поверхности: 14 @method + `fake()` (было 17 @method + 3 assert*
  без классификации).

## 5. Заметки P3-исполнителю (наблюдения, НЕ предписания механики)

- Тесты `tests/Feature/PanelEnumIdentityTest.php:21`, `tests/Feature/ClassPermissionTest.php:21`,
  `tests/Feature/IntegrationPolishTest.php:67` зовут `AzGuard::tryPermission`/`panelIdForPermission`
  фасадной формой; `tests/Feature/IntegrationPolishTest.php:28-29`, `tests/Feature/ExtensionSwapTest.php:69`
  — `AzGuard::isSuperAdmin`. После де-публикации тестам легально звать @internal
  (они пиновали поведение резолюции, не публичность) — решение за P3.1.
- `docs/recipes/temp-access-via-grant.md:33` всё ещё показывает позиционный
  `AzGuard::revoke(...)` — рассинхрон с @internal-вердиктом P2.3; дом фикса —
  P2.10 doc-sweep (уже в его Scope «свип под новый API»), НЕ P3.
- `packages/core/resources/boost/skills/azguard-development/SKILL.md:102` упоминает
  `AzGuard::isSuperAdmin($user)` — регенерация bundled-скилла после cut-line
  (`laravel-package-generate-skill`) — кандидат в P3.1-свип или P2.10.
- `SwapTestManager` (tests/Stubs) и `AzGuardFake` реализуют интерфейс целиком —
  @internal-пометки сигнатур не меняют, правок не требуют.

## 6. Воспроизводимость счётчиков

Все счётчики этой спеки — на дереве `9190e8d`:
- docs-исп.: `grep -roP 'AzGuard::\w+' docs --include='*.md' | grep -v '^docs/ru/' | awk -F: '{print $NF}' | sort | uniq -c`
- внутренние вызовы фасадной формы: `grep -rn 'AzGuard::' packages/*/src --include='*.php' | grep -v 'Facades/AzGuard.php' | grep -oP 'AzGuard::\w+' | sort | uniq -c`
  (единственный хит `AzGuard::resolveRole` — текст docblock-комментария в
  `HasScopedRoles.php:39` про трейт `HasAzGuard::resolveRole()`, не вызов фасада)
- interface-потребители мёртвых-по-C-B4 резолверов: `grep -rn 'tryPermission\|panelIdForPermission' packages --include='*.php'`
