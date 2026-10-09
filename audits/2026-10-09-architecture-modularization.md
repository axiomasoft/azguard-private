# Архитектура: минимальное ядро и подключаемые модули (обсуждение)

Дата: 2026-10-09. Ветка: `review/v1-architecture` от `review/v1-hardening` (`c04529e8`). Документ —
предложения для обсуждения, **код не менялся**. Ссылки на код — относительно `packages/core/src`, если не указано
иное. Цифры сняты скриптом по дереву ветки (строки — `wc -l` с комментариями, связи — по `use AzGuard\…`).

## 0. Контекст

Обсуждали с владельцем по итогам оценки пакета (архитектура 8/10, API и удобство 7,5/10). Позиция владельца:
функционала много и не всем он нужен; при этом пакет не «переусложнён целиком», но часть можно отделить. Цели:

1. **Понятность.** Новичок должен освоить малое ядро, а не 25 зон и 246 публичных классов.
2. **Гибкость.** Возможности, которые нужны не всем, подключаются явно.
3. **Open-core как вариант.** База бесплатная (open source), сложная механика — потенциально платная.

Ограничение: кардинальные изменения в 1.0 сейчас не делаем. Ниже — что я поменял бы, если бы было можно, и что
дёшево сделать уже в 1.0, чтобы эту дверь не закрыть.

**Короткий вывод.** Делить надо не «по фичам из README», а по зависимостям. Ядро у пакета уже есть и хорошее
(`Kernel` + конвейер решений + `DatabaseSource`), но три вещи мешают выделить модули: (1) `Contracts` зависит от
14 зон реализации, то есть стабильного SPI для внешних модулей пока нет; (2) тенанты и области пронизывают 141 из 421
файла — это измерение модели, а не плагин; (3) первый же шаг квик-старта — панель. Отсюда план: в 1.0 — границы
и арх-тесты без смены API; в 1.x — вынести то, что слабо связано (аудит, UI-метаданные, генераторы); в 2.0 —
чистый SPI и, если захотим, платный слой поверх него. Корректность и безопасность платными быть не должны.

## 1. Что есть сейчас: инвентаризация

### 1.1. Размер

| Пакет | Файлов | Строк | Публичный API (`api-manifest.json`) |
|:--|--:|--:|:--|
| `packages/core` (`axiomasoft/azguard`) | 421 | 41 475 | 246 типов (179 классов, 42 интерфейса, 16 enum, 9 трейтов), 1 079 методов |
| `packages/filament` (`axiomasoft/azguard-filament`) | 42 | 5 264 | использует 52 типа ядра, только из манифеста (`tests/Arch/ApiManifestTest.php`) |
| `tests/` | 867 | ~55 800 | из них Feature 251 файл / 27,9 тыс. строк, Fixtures 470 / 14,8 тыс. |
| `docs/` | 23 стр. | ~19,7 тыс. слов | `advanced/` — 4 тыс. слов, из них `consistency.md` 1,4 тыс. |

Конфиг `config/azguard.php`: 221 строка, 11 верхних секций. `PanelBuilder` (`Panels/PanelBuilder.php`): 31
публичный метод DSL. Исключений — 51 класс (`Exceptions/`), причин решения — 21 (`Kernel/Decision/DecisionReason.php`).

### 1.2. Возможности по областям

Строки — суммарно по перечисленным файлам; одна возможность часто размазана по нескольким зонам.

| Возможность | Где (основное) | Строк | Кому нужна |
|:--|:--|--:|:--|
| Ядро значений: идентичности, ключи, шаблоны, `Decision`, `PermissionSet`, токены | `Kernel/` (29 файлов) | 2 336 | всем |
| Конвейер решения: Prepare → Boundary → Before → Authority → Restriction → After | `Authorization/Pipeline/*`, `Authorizer.php`, `EvaluationFrame.php` | ~1 700 | всем |
| Панели: DSL, рецепт слоями, компиляция, реестр, резолвер, отпечаток | `Panels/` (14) | 3 938 | всем, но многопанельность — не всем |
| Каталог прав и ролей из кода (enum + классы ролей, атрибуты) | `Catalog/`, `Roles/`, `Permissions/`, `Attributes/` | ~1 620 | всем |
| Политики (вето) и мост в Laravel Gate | `Policies/`, `Laravel/Gate/GateBridge.php` | 573 | всем |
| Хранилище и `DatabaseSource`: гранты, записи, блокировка панели | `Storage/` (18), `Sources/Database/` (7) | 4 741 | всем, кто хранит гранты в БД |
| Прочие источники: папка (discovery), Gate, Relation, менеджер | `Sources/Folder`, `Gate`, `Relation`, `SourceManager.php` | ~2 160 | discovery — многим; Relation/внешние — немногим |
| Согласованность: снимок, токены, ревизии, эпоха, забор внешних источников, `Reads`/`StateRefresh` | `Authorization/ReadAttempt.php`, `Storage/StorageReadSession.php`, `AuthorityReadBaseline.php`, `Kernel/Decision/*StateToken.php`, `Contracts/Sources/FencesReads.php`, `Panels/Reads.php`, `StateRefresh.php` и др. | ~1 820 + часть `DatabaseSource` | корректность нужна всем; ручки — немногим |
| Тенанты и области назначения (наследование, членство, резолверы) | `Scopes/` (19), `Contracts/Scopes/` (17), `Authorization/ScopeEligibility.php`, `Kernel/Identity/AccessScope.php` | ~1 910 ядра + упоминания в 141 файле | SaaS/мультитенант |
| Видимость списков `visibleTo()` (предикаты в SQL) | `Authorization/Visibility.php`, `Authorization/Query/*`, `Scopes/Query/*`, `Sources/Relation/*` | ~2 150 | многим |
| Батч решений `DecisionSet` одним снимком | `Authorization/BatchEvaluation.php`, `BatchInputs.php`, `Kernel/Decision/DecisionSet.php` | 751 | таблицам/Filament |
| Хуки, ограничения, условия грантов | `Contracts/Authorization/{Restriction,GrantCondition}.php`, `Pipeline/Stages/{Before,Restriction,After}Stage.php` | ~370 | продвинутым |
| Супер-админ | `Roles/SuperAdminRole.php`, `Roles/Attributes/SuperAdmin.php` (+ 27 файлов упоминаний) | 38 | многим |
| Конвейер изменений: pipes, валидаторы, журнал, менеджеры, миграция ключей ролей | `Changes/` (24) | 2 965 | запись нужна всем; pipes/валидаторы — продвинутым |
| События (после commit, с актором) | `Events/` (15) | 899 | многим |
| Плагины и аудит | `Plugins/` (`BasePlugin`, `PluginContext`, `Audit/*`) | 247 (аудит 172) | аудит — компаниям с compliance |
| Кэш наборов прав и каталога (array/redis через Laravel cache) | `Authorization/Cache/PermissionSetCache.php`, `Catalog/CatalogCache.php` | 293 | производительность |
| UI-метаданные: схемы полей, справочники субъектов/тенантов/областей | `Schema/` (11), `Directories/` (11) | 1 937 (без контрактов) | в основном Filament |
| Диагностика: `doctor` (25 проверок), `explain`, обзор панели | `Diagnostics/` (30), `Laravel/Console/Commands/{Doctor,Explain}Command.php` | 2 234 + | всем в CI; глубина — продвинутым |
| Консоль: 33 команды, из них 10 `make:*` и скаффолдинг | `Laravel/Console/` | 3 279 (make 921, scaffold 507) | частично |
| HTTP: middleware, `DecisionResponder`, эталонный маппинг 403/503 | `Laravel/Http/` | 624 | всем |
| Тестовый набор: `AzGuardFake`, `actingAsWithRoles`, контрактные сьюты для авторов расширений | `Testing/` (24) | 2 135 | всем; контракты — авторам расширений |
| Filament: авторизация ресурсов, фильтрация, редакторы грантов, экспорт | `packages/filament/src` | 5 264 | пользователям Filament |

### 1.3. Связность (кто от кого зависит)

Исходящие связи зон (число других зон, которые зона импортирует):

| Зона | → зон | Комментарий |
|:--|--:|:--|
| `Kernel` | 1 (`Exceptions`) | чистое PHP-ядро, закреплено `tests/Arch/ZonesArchTest.php` («kernel depends on nothing but PHP») |
| `Exceptions` | 1 (`Kernel`) | закреплено арх-тестом |
| `Events` | 1 | хорошо |
| `Storage` | 5 | приемлемо (`Panels\Reads` — enum настройки) |
| `Policies`, `Roles`, `Scopes`, `Directories` | 3–6 | приемлемо |
| `Panels`, `Catalog`, `Schema` | 9–11 | сборка; взаимные связи `Panels ↔ Catalog ↔ Scopes ↔ Sources` |
| `Authorization`, `Changes` | 12 | ожидаемо для оркестраторов |
| `Diagnostics` | 13 | ожидаемо |
| **`Contracts`** | **14** | **проблема для SPI**: контракты ссылаются на реализации |
| **`Sources`** | **14** | `DatabaseSource` импортирует 4 doctor-проверки и 6 классов `Changes` |
| `Laravel` | 20 | слой интеграции, ожидаемо |

Входящие (fan-in): `Kernel` и `Exceptions` — 20 зон, `Panels` и `Contracts` — 17, `Scopes` и `Sources` — 13. То есть
тенанты/области и источники — такая же опора, как панели.

Конкретные связи, которые мешают модульности:

- **Контракты на реализации.** `Contracts/PanelAccess.php` → `Concerns\SubjectAccess`, `Directories\PanelDirectories`,
  `Authorization\Visibility`, `Panels\Panel`; `Contracts/AzGuardSubject.php` → `Concerns\SubjectAccess`,
  `Concerns\SubjectPanels`; `Contracts/Sources/StoresGrants.php` → `Changes\Change`, `Changes\ChangeResult`;
  `Contracts/Plugins/Plugin.php` → `Panels\PanelBuilder`, `Plugins\PluginContext`; `Contracts/Diagnostics/DoctorCheck.php`
  → `Diagnostics\DoctorContext`. Часть этого — значения (DTO), которые просто живут не в той зоне; часть —
  конкретные классы (`PanelBuilder`, `SubjectAccess`), которые фактически стали API.
- **Источник знает про диагностику.** `Sources/Database/DatabaseSource.php:28-31` импортирует
  `Diagnostics\Checks\{DecisionFieldsInMeta,GrantsDead,ModelColumns,RolesOrphaned}`; `Sources/Folder/FolderSource.php:20`
  — `DiscoveryCached`. Правильнее, чтобы источник поставлял свои проверки через `doctorChecks()`, не зная классов зоны.
- **Фасад знает про тестовый набор.** `AzGuardManager.php:31` → `Testing\AzGuardFake` (для `AzGuard::fake()`).
  Для Laravel это норма (`Bus::fake()`), но это мешает вынести `Testing` в dev-пакет.
- **Тенанты в ядре значений.** `Kernel/Identity/AccessScope.php` — пара `TenantRef` + `AssignmentScopeRef`; 7 файлов
  `Kernel`, 13 из 20 файлов `Authorization`, 6 причин решения (`tenant_*`, `context_*`) завязаны на них. Это
  осознанное решение (изоляция тенантов в типах), и именно поэтому тенанты нельзя «просто вынести в плагин».

### 1.4. Что спроектировано хорошо и должно остаться

- **`Kernel` без фреймворка**, закреплённый арх-тестом и проверкой хелперов. Это готовая основа «ядра ядра».
- **Один конвейер для всех входов** (`hasPermission`, `@can`, middleware, атрибуты, Filament, `visibleTo`). Деление
  на модули не должно порождать второй путь решения.
- **Fail closed с различимым отказом** (`Decision::failed()`, `FailureKind`). Это свойство безопасности, не фича.
- **Плагины уже есть и работают.** `Contracts/Plugins/Plugin.php` (`register(PanelBuilder)`, `boot(Panel)`),
  зависимости между плагинами (`Contracts/Plugins/DependsOnPlugins.php`), отключение по id (`withoutPlugins`).
  `Plugins/Audit/AuditPlugin.php` (`azguard/audit`) — живой пример модуля поверх SPI.
- **Источники как контракты-способности** (`Contracts/Sources/Provides*`, `StoresGrants`, `FiltersQueries`,
  `FencesReads`, `ChecksHealth`): источник реализует только то, что умеет.
- **Контрактные тест-сьюты для авторов расширений** (`Testing/Contracts/*ContractTests.php`) — то, что нужно
  экосистеме и будущему pro-слою.
- **Манифест публичного API** (`packages/core/api-manifest.json`, `bin/api-manifest.php --check`) и арх-тест,
  что Filament пользуется только им. Это механизм, на котором держится будущий стабильный SPI.
- **Монорепо со split** (`.github/workflows/split.yml`) и `self.version` между пакетами — выделение новых пакетов
  технически дёшево.

Вывод по разделу: архитектура не «плохая», она **плотная**. Много правильных механизмов, но их границы видны
только по коду, а не по пакетам, документации и SPI.
