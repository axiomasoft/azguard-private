# P0 ось B — гибкость / расширяемость / fluent API (2026-07-18)

> Слой 1. Аудитор: fable-субагент (аудитор-исполнитель P0.3). Read-only аудит по чеклисту
> phases/P0.md §P0.3. Приоры B.6–B.9 (research/01-fluent-api-priors.md §B) проверены кодом;
> RAG-каноны — findings/P0-rag-fluent-dx.md. Известное отклонение: у фасада фактически
> **17** @method (`grep -c '@method' packages/core/src/Facades/AzGuard.php` = 17), а не 18,
> как в recon/ТЗ item'а — таблица C-B4 покрывает все 17. Также фактические счётчики
> контрактов: src/Contracts/ = 16 интерфейсов, Registry/Contracts/ = 6 (recon: «20 + 7»).

## Чеклист

| C# | Проверка | Вердикт | Якоря | Заметка |
|:--|:--|:--|:--|:--|
| C-B1 | Swappable-контракты (manager/resolver/matcher/abilities_resolver/role_permission_validator): реальный ли шов, тест на подмену, документированность | partial | packages/core/config/az-guard.php:39-48; tests/Feature/ExtensionSwapTest.php:43-44; docs/advanced/configuration.md:9 | Все 5 швов реальны и покрыты swap-тестами; в docs/ не документирован ни один → B-01 |
| C-B2 | GrantSource/GrantPriority: расширяемость, порядок приоритетов | partial | packages/core/config/az-guard.php:171; tests/Feature/CustomGrantSourceTest.php:31 | Интерфейс чистый, gaps приоритетов документированы (GrantPriority.php:11-13), extending.md §Custom GrantSource; config `grant_sources` (restrict/reorder) без теста → B-10 |
| C-B3 | PermissionCatalog(Builder): эргономика построения каталога | partial | tests/Feature/CustomCatalogBuilderTest.php:32-33; packages/core/src/Registry/Contracts/PermissionCatalog.php:55-59 | registerCatalogBuilder + SimplePermissionDefinition эргономичны; runtime-регистрация требует `forgetInstance` — контракт flush() не выполняется → B-07 |
| C-B4 | Фасад: классификация всех @method против локуса spatie (трейт/Gate, не фасад) + grep docs-использования | partial | packages/core/src/Facades/AzGuard.php:21-41 | 17 @method; 8/17 ни разу не встречаются в docs-примерах; tryPermission/panelIdForPermission — 0 потребителей вообще → B-05; таблица ниже |
| C-B5 | Grant-грамматика: позиционные шорткаты vs fluent, расхождения core↔context | fail | packages/core/src/Grants/GrantBuilder.php:30; packages/context/src/ContextGrantBuilder.php:38; docs/advanced/context.md:133 | Две несведённые грамматики: фасадный корень forUser() только в core, context — `new ContextGrantBuilder`; TTL только в core → B-03 (приор B.6 подтверждён) |
| C-B6 | Полиморфизм типов по всем входным точкам permission/role | partial | packages/core/src/AzGuardManager.php:117; packages/core/src/Concerns/HasRoles.php:42-105; packages/filament/src/AzGuardPlugin.php:43 | Permission-ключи однородны (string\|UnitEnum везде); panelId и role — неоднородны → B-04; PermissionKey как входной VO не принимается нигде (часть приора A.4 кодом не подтверждена); карта ниже |
| C-B7 | Middleware: статические `::using()`-конструкторы против конвенции spatie/Laravel | fail | packages/core/src/Http/Middleware/CheckDirectGrant.php:38-43; packages/core/src/AzGuardServiceProvider.php:314-317 | Ни одного `public static function using` в src (grep = 0); только строковый alias-DSL → B-02 (приор A.5, канон RAG:✅; ось A только ссылается сюда) |
| C-B8 | Config-как-DSL: ключи-ПОВЕДЕНИЕ (кандидат fluent/registration-API) vs wiring/дефолты | partial | packages/core/config/az-guard.php:131,138-141,182,194,203; packages/filament/config/az-guard-filament.php:32,50,105-106 | Wiring-ключи (классы, таблицы, модели) — канонично; поведенческие флаги и шаблон-DSL ключей Filament — в config при одном fluent-сеттере плагина → B-06 (приор B.8) |
| C-B9 | Immutable builders: `final readonly` + with-методы + явный терминальный глагол (канон F49, ArchTest) | partial | packages/core/src/Grants/GrantBuilder.php:32-36; packages/context/src/ContextGrantBuilder.php:40-44; tests/ArchTest.php:121-124 | Терминальные глаголы явные (grant/revoke/revokeAll/grants — хорошо); сами builders мутабельны (setters `return $this`, не readonly/with-*), арх-ратчет покрывает только Registry\Values → B-08 |
| C-B10 | Context MergeStrategy: расширяем ли потребителем собственный merge, документированность шва | partial | packages/context/src/AzGuardContextServiceProvider.php:50; docs/advanced/context.md:191-194; tests/Unit/Context/MergeStrategyTest.php:11 | Шов реальный (config merge_strategy) и документирован (MyStrategy implements MergeStrategy); теста подмены кастомной стратегии через config нет → B-09 |

## C-B4 — классификация 17 @method фасада AzGuard

Локус-эталон (RAG:✅): у spatie фасада нет — API живёт в трейте User + моделях + Gate;
фасад azguard мерится как УЗКИЙ оркестровый вход (panels/catalog/extensions, fluent-корни).
Использование — `grep -oP 'AzGuard::\w+' docs/**/*.md`.

| # | @method | Классификация | docs-исп. | Вердикт |
|:--|:--|:--|:--|:--|
| 1 | registerPanel | оркестровый (панели) | 0 | оставить (SP/PanelProvider-путь) |
| 2 | getPanels | оркестровый / интроспекция | 0 | оставить (CLI/doctor) |
| 3 | panel | оркестровый lookup | 0 | оставить (используют middleware) |
| 4 | currentPanel | оркестровый (request-state) | 0 | оставить (CheckAccess.php:99) |
| 5 | setCurrentPanel | оркестровый (request-state) | 0 | оставить (SetCurrentPanel.php:26) |
| 6 | permission | резолвер ключа | 23 | оставить (главный docs-метод) |
| 7 | tryPermission | резолвер ключа | 0 | кандидат-вылет: 0 docs + 0 внутренних вызовов |
| 8 | panelIdForPermission | резолвер lookup | 0 | кандидат-вылет: 0 docs + 0 внутренних вызовов |
| 9 | registerGrantSource | оркестровый (extension) | 4 | оставить |
| 10 | registerCatalogBuilder | оркестровый (extension) | 2 | оставить |
| 11 | isSuperAdmin | предикат — дубль трейта (HasPermissions.php:149) | 0 | кандидат-вылет (локус — трейт) |
| 12 | abilitiesFor | оркестровый (frontend-проекция) | 4 | оставить |
| 13 | hasContextGuard | предикат — дубль трейта (HasPermissions.php:95) | 2 | кандидат (дубль; полезен без user — решать в P3) |
| 14 | forUser | fluent-корень | 23 | оставить (ядро fluent-грамматики) |
| 15 | grant | shorthand — позиционный дубль fluent | 4 | кандидат-пересмотр (грамматика, см. B-03) |
| 16 | revoke | shorthand — позиционный дубль fluent | 3 | кандидат-пересмотр |
| 17 | grants | shorthand — позиционный дубль fluent | 2 | кандидат-пересмотр |

Итог: оркестровых 10 · резолверов 3 (2 мёртвых) · предикатов-дублей 2 · fluent-корень 1 ·
позиционных дублей fluent 3. Решение cut-line — P3 (здесь только классификация).

## C-B6 — карта полиморфизма входных типов

| Входная точка | permission | panelId | role |
|:--|:--|:--|:--|
| Manager permission/tryPermission (AzGuardManager.php:81,95) | string\|UnitEnum | string\|BackedEnum | — |
| Manager grant/revoke/grants (AzGuardManager.php:212-251) | string\|UnitEnum | string\|BackedEnum\|null | — |
| Manager abilitiesFor (AzGuardManager.php:140) | list\<string> keys | string\|BackedEnum\|null | — |
| Manager isSuperAdmin (AzGuardManager.php:117) | — | **?string — БЕЗ enum** | — |
| HasPermissions::hasPermission/checkPermission (HasPermissions.php:31,114) | string\|UnitEnum | **?string — БЕЗ enum** | — |
| HasPermissions::isSuperAdmin/permissionSet/permissions/flushPermissions (:127-159) | — | ?string | — |
| HasDirectGrants::grant/revoke (HasDirectGrants.php:75,94) | string\|UnitEnum | **string обязат., БЕЗ enum** | — |
| HasDirectGrants::hasGrant / grants (:57,41) | string\|UnitEnum | ?string / string | — |
| HasRoles::hasRole/assignRole/removeRole/syncRoles (HasRoles.php:42-105) | — | — | **string\|Role — БЕЗ BackedEnum** |
| HasScopedRoles::* (HasScopedRoles.php:101-251) | string\|UnitEnum | ?string | string\|Role |
| Middleware azguard.grant (CheckDirectGrant.php:38-43) | string (DSL) | ?string (DSL) | — |
| Middleware azguard.panel_check (PanelCheckAccess.php:27) | string (DSL) | string (DSL) | — |
| Middleware azguard.panel (SetCurrentPanel.php:19) | — | string (DSL) | — |
| Middleware azguard.check → #[CheckPermission] (CheckAccess.php:99) | UnitEnum (атрибут) | — | — |
| GrantBuilder::grant/revoke / on (GrantBuilder.php:83,44) | string\|UnitEnum | string\|BackedEnum | — |
| ContextGrantBuilder::grant/revoke / on (ContextGrantBuilder.php:76,52) | string\|UnitEnum | string\|BackedEnum | — |
| AzGuardPlugin::forPanel (AzGuardPlugin.php:43) | — | **string — БЕЗ enum** | — |
| Gate-шов Authorizer::check (Guard/Authorizer.php:36) | string ability | — | — (граница Gate — string by design) |

Однородно: permission-ключ — string|UnitEnum везде, где принимается. Неоднородно: panelId
(builders/manager-grants принимают BackedEnum, трейты/isSuperAdmin/плагин — нет); role —
BackedEnum не принимается нигде (канон spatie v6 — принимает, RAG:✅). VO `PermissionKey`
(PermissionKey.php:29) — статический нормализатор, входным типом не является нигде.

## C-B5 — расхождения grant-грамматики core↔context

| Аспект | core GrantBuilder | ContextGrantBuilder |
|:--|:--|:--|
| Вход | `AzGuard::forUser($user)` — fluent-корень фасада (AzGuardManager.php:201) | `new ContextGrantBuilder($user)` — руками, корня нет (docs/advanced/context.md:133) |
| Позиционный shorthand | `AzGuard::grant($user, $key, $panelId, $ttl)` (AzGuardManager.php:212) | отсутствует |
| TTL / expiry | ttl() + expiresAt(), grants() фильтрует active() (GrantBuilder.php:54,67,171) | отсутствует; grants() без фильтра (ContextGrantBuilder.php:171) |
| Скоуп-сеттеры | on() | on() + inContext() |
| Терминалы / события | grant/revoke/revokeAll/grants + events | симметрично |

Кандидаты унификации (фиксация, не решение): единый fluent-корень (`forUser()` с
context-расширением), фасадный/хелперный вход для context-builder, TTL-парность.

## Находки

### B-01 — Все 5 swappable-швов не документированы в docs

- **Severity:** Major
- **Чек:** C-B1
- **Где:** docs/advanced/configuration.md:9; docs/advanced/extending.md:1
- **Суть:** manager/resolver/matcher/abilities_resolver/role_permission_validator описаны только комментариями config; extending.md их не упоминает, а configuration.md заявляет «full file with annotations», но отстал от реального конфига (нет Extension Points, default_panel, strict_panels, grant_sources, fail_on_source_exception и др.; содержит несуществующий `cache.key`). Швы расширяемости — акцент брифа — невидимы потребителю.
- **Рекомендация:** секция extending.md «Swapping core services» по всем 5 ключам + синхронизация configuration.md с фактическим az-guard.php.

### B-02 — Нет статических `::using()`-конструкторов у middleware

- **Severity:** Major
- **Чек:** C-B7
- **Где:** packages/core/src/Http/Middleware/CheckDirectGrant.php:38; packages/core/src/AzGuardServiceProvider.php:314-317
- **Суть:** Все 5 middleware конфигурируются только строковым alias-DSL (`azguard.grant:app.documents.export,app`); канон spatie v6 / Laravel 10.9+ — параллельные статические конструкторы (`PermissionMiddleware::using(...)`, `Authorize::using(BackedEnum)`), RAG:✅ P0.1 Запрос 2. Нет типизации, автодополнения и enum-входа на маршрутах.
- **Рекомендация:** добавить `::using(string|BackedEnum ...)` к параметризуемым middleware (grant/panel/panel_check/check), alias-DSL оставить параллельным путём. Направление — P2.

### B-03 — Две несведённые grant-грамматики core↔context

- **Severity:** Major
- **Чек:** C-B5
- **Где:** packages/context/src/ContextGrantBuilder.php:38; packages/core/src/AzGuardManager.php:201-258
- **Суть:** Core даёт фасадный fluent-корень + позиционные шорткаты, context — только ручной `new ContextGrantBuilder($user)` без корня и без TTL/expiry. Потребитель мультиворкспейса учит две грамматики одной операции «выдать право» (приор B.6 подтверждён кодом).
- **Рекомендация:** зафиксировать в P2 единую грамматику (общий fluent-корень, TTL-парность или явное решение «context-грант бессрочен by design»).

### B-04 — Полиморфизм panelId/role неоднороден против канона string|BackedEnum

- **Severity:** Major
- **Чек:** C-B6
- **Где:** packages/core/src/AzGuardManager.php:117; packages/core/src/Concerns/HasRoles.php:66; packages/core/src/Concerns/HasDirectGrants.php:75
- **Суть:** panelId принимает BackedEnum в builders и grants-методах менеджера, но не в isSuperAdmin, трейтах (HasPermissions/HasDirectGrants/HasScopedRoles) и AzGuardPlugin::forPanel; role-аргументы (assignRole/hasRole/syncRoles/scoped-roles) не принимают BackedEnum нигде — вопреки канону spatie v6 (RAG:✅). Один ментальный ввод «string|enum везде» не выдержан.
- **Рекомендация:** выровнять границу до `string|BackedEnum` на всех входах panelId/role (unwrap на границе); реализация — P1/P2 по карте C-B6.

### B-05 — Фасад шире реального локуса: мёртвые и дублирующие методы

- **Severity:** Minor
- **Чек:** C-B4
- **Где:** packages/core/src/Facades/AzGuard.php:27-28,33
- **Суть:** 8/17 @method не встречаются в docs-примерах; tryPermission и panelIdForPermission не имеют ни docs-, ни внутренних потребителей; isSuperAdmin/hasContextGuard дублируют трейт. Против локуса spatie (трейт/Gate — центр) фасад несёт балласт.
- **Рекомендация:** классификацию передать в P3 (cut-line); мёртвые резолверы — первые кандидаты `@internal`/вылет.

### B-06 — Filament-плагин config-центричен против канона v5

- **Severity:** Major
- **Чек:** C-B8
- **Где:** packages/filament/src/AzGuardPlugin.php:28-31,43; packages/filament/config/az-guard-filament.php:32,50,105-106
- **Суть:** Канон Filament v5 (RAG:✅ P0.1 Запрос 1): поведение плагина — fluent-сеттеры на объекте, `make()` через `app(static::class)`. У AzGuardPlugin один fluent-сеттер forPanel() (и тот задублирован ключом config `panel`), `make()` = `new self` (swap в рантайме невозможен); enforce/source/abilities/шаблон ключа `'{panel}.{resource}.{ability}'`+case (string-DSL) — только в config.
- **Рекомендация:** P2: перенести поведенческие опции в fluent-сеттеры плагина (config — fallback), `make()` — через контейнер.

### B-07 — Контракт PermissionCatalog::flush() не работает для runtime-регистрации builders

- **Severity:** Minor
- **Чек:** C-B3
- **Где:** packages/core/src/Registry/Contracts/PermissionCatalog.php:55-59; packages/core/src/AzGuardServiceProvider.php:170-183; tests/Feature/CustomCatalogBuilderTest.php:33
- **Суть:** Докблок flush() обещает пересборку «after registering builders/panels at runtime», но список builders замораживается `iterator_to_array(tagged)` при инстанцировании singleton'а — flush() не пере-собирает теги; собственный тест обходит это через `app()->forgetInstance(PermissionCatalog::class)`. Потребитель, следующий контракту flush(), тихо получит устаревший каталог.
- **Рекомендация:** либо сузить докблок flush() (панели — да, builders — только до инстанцирования), либо сделать сбор builders ленивым, как panels().

### B-08 — Builders мутабельны и вне арх-ратчета

- **Severity:** Minor
- **Чек:** C-B9
- **Где:** packages/core/src/Grants/GrantBuilder.php:32-36; packages/context/src/ContextGrantBuilder.php:40-44; tests/ArchTest.php:121-124
- **Суть:** GrantBuilder/ContextGrantBuilder — final, терминальные глаголы явные (канон-часть выполнена), но сеттеры мутируют состояние (`return $this`), а не `readonly`+with-методы (приор B.9/канон F49); арх-ратчет «final readonly» покрывает только Registry\Values — namespace'ы Grants/Context builders не защищены от регрессии.
- **Рекомендация:** решить в P2 канон для builders (immutable-with или осознанно мутабельный) и расширить ArchTest на выбранный канон.

### B-09 — Подмена MergeStrategy через config не покрыта тестом

- **Severity:** Minor
- **Чек:** C-B10
- **Где:** packages/context/src/AzGuardContextServiceProvider.php:50; tests/Unit/Context/MergeStrategyTest.php:11
- **Суть:** Шов документирован (docs/advanced/context.md:191-194), но тесты покрывают только прямые вызовы 3 встроенных стратегий; подмена кастомной стратегии через `az-guard-context.merge_strategy` (реальный путь потребителя) не проверяется — регрессия резолюции config→binding пройдёт незамеченной.
- **Рекомендация:** swap-тест по образцу ExtensionSwapTest (кастомная стратегия через config) — вход P4.

### B-10 — Config `grant_sources` (restrict/reorder) без теста

- **Severity:** Minor
- **Чек:** C-B2
- **Где:** packages/core/config/az-guard.php:171; packages/core/src/Support/Config.php:228
- **Суть:** Документированный потребительский рычаг «явный список = ограничить/переупорядочить источники» не имеет ни одного теста (grep `grant_sources` по tests/ пуст) — поведение allowlist-ветки Config не зафиксировано.
- **Рекомендация:** feature-тест restrict/reorder — вход P4.

### B-11 — @method-докблоки фасада уже реальных сигнатур менеджера

- **Severity:** Minor
- **Чек:** C-B6
- **Где:** packages/core/src/Facades/AzGuard.php:23,26-27,39-41; packages/core/src/AzGuardManager.php:63,81,212
- **Суть:** Докблоки заявляют `panel(string $id)`, `permission(string $panelId, ...)`, `grant(..., ?string $panelId)`, тогда как менеджер принимает `string|BackedEnum` — IDE скрывает enum-полиморфизм от потребителя фасада (статически «неправильный» тип при рабочем рантайме).
- **Рекомендация:** синхронизировать @method-типы с сигнатурами AzGuardManagerInterface (лёгкий P1-фикс).

## Cross-ref

- C-B7/B-02 — каноническая запись гэпа `::using()`: ось A (C-A3) только ссылается сюда.
- Читаемость строкового DSL middleware для потребителя — ось A (C-A3), здесь не дублируется.
- Семантика merge-стратегий (эскалация привилегий) — ось C (C-C7); здесь только расширяемость шва.
- Doc-DX структуры docs в целом — ось A (C-A11); B-01 фиксирует только невидимость швов расширения.
