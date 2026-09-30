# 13 — Рабочие потоки: фазы и пункты для плана

Нумерация `Pn.m` стабильна: на неё ссылаются сценарии [14-verification.md](14-verification.md). План создаётся по
протоколу проекта (`plans/ACTIVE.md`); фазы и пункты переносятся **без перенумерации**.

Коротко, в каком порядке. Пакет ещё не в работе, поэтому старый код не патчится: новая версия строится с нуля по
досье. Сначала основа: ядро понятий (F1), панели и правило выбора панели (F2), хранилище (F3). На ней — проверка
прав со всеми источниками (F4), затем модель и изменения (F5). В конце — Laravel-поверхность, Filament, интеграции и
выпуски (F6–F8).

## 0. Правила исполнения (переносятся в `Execution Rules` плана)

1. **Совместимости с 0.3 нет** ([D01](02-decisions.md#d01)): без алиасов, нормализатора старого конфига, миграции
   данных. Старый код удаляется в том пункте, где появляется замена.
2. **Probes — регрессионные требования.** Каждый probe из [evidence](evidence/README.md) переписывается на новый API
   с **обратным** ожиданием (дефект невозможен) в пункте, указанном в колонке «Probe». Пункт без такого теста не
   закрыт.
3. Исполнитель читает только указанные разделы досье; расхождение досье и кода → вопрос в `open-questions.md`, не
   импровизация.
4. Пункты с маршрутизацией **opus** не отдаются младшим моделям без готовой спецификации (`findings/Pn.m.md`).
5. После каждого пункта: `composer test`, Pint, PHPStan (уровень проекта), arch-тесты; падение или пропуск ≠ успех.
6. **Встроенные источники пишутся только через публичные контракты** (arch-тест из
   [04 §2](04-packages-and-layout.md#2-зоны-ядра--что-где-лежит-и-почему)). Не хватает контракта — сначала контракт.
7. Имена — только из [03](03-glossary-and-renames.md).
8. Код Vaulter и других пакетов из этого плана **не** меняется. Идея моста и заметки
   ([10 §9–§10](10-integrations.md#9-идея-моста-vaulter--azguard)) передаются в план Vaulter отдельной задачей.

## F0 — Подготовка

| Пункт | Что | Готово когда | Модель |
|---|---|---|---|
| P0.1 | ADR «Ecosystem conventions» (текст из [10 §8.1](10-integrations.md#81-общие-инженерные-правила-adr-ecosystem-conventions-один-текст-в-репозиториях-экосистемы)) в `docs/adr/` | ADR в репозитории; предложение передано владельцам других пакетов | sonnet |
| P0.2 | Сценарии probes → спецификации регрессионных тестов на новом API (`tests/Regression/*.md` → тесты по ходу F1–F7) | у каждого probe есть файл-спецификация с пунктом, который его закроет | sonnet |
| P0.3 | Каркас consumer-фикстуры (чистый Laravel + собранные архивы) в CI | job работает (пока без блокировки) | sonnet |
| P0.4 | Composer-имена `axiomasoft/azguard`, `axiomasoft/azguard-filament`; `homepage` → `github.com/axiomasoft/azguard`; context-пакет помечен к удалению | `composer validate` зелёный | sonnet |

## F1 — Ядро понятий

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P1.1 | `Kernel\Identity\*`: `PermissionKey`, `PermissionPattern`, `RoleKey`, `SubjectRef`, `ContextRef`, `AnyContext`, `ActorRef`, `IdentityCodec` | [05 §5](05-php-api.md#5-значения-ядра-azguardkernel), D07 | property-тесты однозначности и round-trip (V01–V04) | P07 | opus |
| P1.2 | Грамматика: `panel:local`, ≥ 2 сегментов, шаблоны `*`/`**`, без голых звёздочек | D18 | таблица грамматики как data-provider | P14 | sonnet |
| P1.3 | Значения решения: `AccessRequest`, `Decision`, `DecisionReason`, `Grant`, `CodeStateToken`/`StateToken`, `BeforeResult`, `PermissionAuthority`, `Explanation`, `PermissionSet` | 05 §5 | unit-тесты значений | — | sonnet |
| P1.4 | Arch-правила зон | [04 §2](04-packages-and-layout.md#2-зоны-ядра--что-где-лежит-и-почему), D02, D12 | arch-набор зелёный | — | sonnet |
| P1.6 | TenantRef/AccessScope, ContextDefinition/ConfigurableContextDefinition/BaseContext, configured role binding/BaseRole/ContextRuntime/LookupContext/PluginContext/ChangeContext, RoleContribution/GrantCondition, Origin и новые typed query/record values; authority semantics до API freeze | D59–D83, 05/06/09 | V86, V89; contract snapshots scoped inputs | — | opus |

## F2 — Панели

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P2.1 | `PanelProvider`, `PanelBuilder`, `Panel`, `PanelRegistry` (заморозка, дубликаты, `replace`, `configure`, `configureAll`), `CurrentPanel` | [05 §4](05-php-api.md#4-описание-панели-panelprovider-и-panelbuilder), D06, D73–D74 | V05–V07, V107–V108 | — | opus |
| P2.2 | `PanelResolver` — одно правило выбора панели; панель по умолчанию; `azguardDefaultPanel()`; префиксы `prefixed()` | [09 §1](09-authorization-semantics.md#1-как-выбирается-панель), D05, D11 | V64 (свойство P9), V65, V81 | P01c, P05, P09 | opus |
| P2.3 | Настройки панели: провайдер → плагины → `configurePanels()` → конфиг; `PanelSettings` с источником значения; гарантии не настраиваются | D45, [07](07-configuration.md) | V47 | — | opus |
| P2.4 | Плагины: `Plugin`, `DependsOnPlugins`, `BasePlugin`/`prefixed`, concrete named typed factory/PluginContext, explicit build/runtime inputs, instance isolation, lifecycle/conflicts/fingerprint D78 | [06 §3](06-extension-points.md#3-плагины), D47 | V48, V85, V115 | — | opus |
| P2.5 | Каталог из источников (`ProvidesPermissions`): статичная часть + динамическая с версией, `#[Describe]`, O(1)-поиск, коллизии, `azguard:catalog:cache` | D36 | V08; бюджет D44 для чужой ability | — | opus |
| P2.6 | Модули: `registerPanel()`, `configurePanel()`, `AmbiguousPanelException` | [06 §8](06-extension-points.md#8-модули-и-сторонние-пакеты-внутри-приложения), D50 | V55 | — | sonnet |
| P2.7 | Фабрика источников: контракты `Source` и возможностей, `SourceManager` (`Illuminate\Support\Manager`), `#[AsSource]`, `config('azguard.sources')`, сборка источников панели, один писатель | [06 §1](06-extension-points.md#1-источники-фабрика), D52, D58, D73 | V77, V107; `SourceContractTests` на встроенных | — | opus |
| P2.8 | `FolderSource`: папка провайдера и `discover()`, домены, атрибуты `#[Resource]`/`#[Describe]`/`#[RequiresGrant]`/`#[GrantedToAll]`, политики по соглашению «метод = кейс», роли с `#[Role]`, `Shared/`; кэш | [05 §6–§7](05-php-api.md#7-ресурсы-и-политики), D14, D56, D72 | V78, V79, V106 | — | opus |

## F3 — Хранилище

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P3.1 | `Storage`, `StorageRegistry`, `Storage::mutate(panel, …)` с повторами и порядком блокировок, версия **на панель**, `ids.host_keys` | [08 §1](08-data-model-and-migration.md#1-что-принадлежит-azguard), [08 §5](08-data-model-and-migration.md#5-порядок-блокировок-и-повторы), D24, D34 | запись без `mutate` невозможна для официальных путей; повтор на deadlock (PG) | — | opus |
| P3.2 | Миграции с префиксом хранилища на PG/MySQL/MariaDB/SQLite; `azguard:storage:migration` | [08 §2](08-data-model-and-migration.md#2-схема-хранилища), D35 | DDL-снимки на 4 СУБД; два хранилища в одной БД не мешают друг другу | — | sonnet |
| P3.3 | Базовые модели (`Role`, `RolePermission`, `RoleGrant`, `PermissionGrant`, `Permission`), свои модели `DatabaseSource` (`#[Table]`/`#[Connection]`), `azguardFields()`, `meta`, `decisionFields` | [08 §3](08-data-model-and-migration.md#3-свои-модели-и-колонки), [06 §6](06-extension-points.md#6-свои-модели-и-поля), D46 | V51 | — | opus |
| P3.4 | Защита от прямых записей (UnsupportedDirectWriteException во всех environments, включая mass writes) | D22 | V97, V105; strict contract | — | sonnet |

## F4 — Проверка прав: источники и пайплайн

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P4.1 | Пайплайн проверки: подготовка, Continue/Deny before, explicit PolicyOnly/RequiresGrant, scoped superadmin, grant-side policy veto, ограничения, after observation; `EvaluationContext`; «ошибка = отказ» | [09 §2](09-authorization-semantics.md#2-пайплайн-проверки-алгоритм), [06 §4](06-extension-points.md#4-хуки-проверки), D17, D20, D48, D55 | свойства P1–P16 (V15), V53 | — | opus |
| P4.2 | Выдачи из папки: `BaseRole` и атрибуты роли, `GrantedAutomatically`, `#[GrantedToAll]`, `#[FormerKeys]` | [05 §6](05-php-api.md#6-роли-в-коде), D14, D52 | V09–V11, V66, V79 | P02 | opus |
| P4.3 | Mode-aware policies: explicit authority/veto (`FolderSource`), `#[Decides]`, `#[PolicyFor]`, `GateSource::map()`, перевод `can('update', $order)` в право домена | [05 §7](05-php-api.md#7-ресурсы-и-политики), [09 §5](09-authorization-semantics.md#5-политики-и-gate-внутри-проверки), D53 | V67, V68, V80 | P03 | opus |
| P4.4 | `DatabaseSource`: назначения PHP-классов ролей и enum прав, без role definitions в БД, шаблоны, сроки, `rolesOnly()`, `dynamicPermissions()` + `PermissionManager`, писатель | D13, D46, D52 | `SourceContractTests` зелёный; V74, V82 | P08 | opus |
| P4.5 | `RelationSource::make(model, via, role)`, видимость через `whereHas` | [06 §2.1](06-extension-points.md#21-связи-сущностей), D52 | V69 | — | opus |
| P4.6 | Контексты: `ContextPolicy`, `on:` → контекст/ресурс, `ContextAware`, резолверы, `withinContext`, членство | [09 §3](09-authorization-semantics.md#3-тенант-контекст-и-ресурс), D15, D16 | V12–V14, V16 | P06, P06b | opus |
| P4.7 | Суперадмин — признак роли: `#[SuperAdmin]` класса, глобально и в сущности, `isSuperAdmin(on:)`; ограничения и `exemptsSuperAdmin()` | [09 §4](09-authorization-semantics.md#4-суперадмин), D19, D20 | V17 | P01a, P14 | sonnet |
| P4.8 | Кэш наборов прав (запрос + store), `StateToken`, отпечаток, `Volatility`, `state_refresh`, `touch()` | [09 §8](09-authorization-semantics.md#8-кэш-и-консистентность), D24, D25 | V18–V19, V49, бюджеты D44 | P10, P10b | opus |
| P4.9 | `decideMany()` | [09 §9](09-authorization-semantics.md#9-пакетная-оценка), D27 | бюджет запросов D44 | — | opus |
| P4.10 | `explain()` + `azguard:explain` | [09 §11](09-authorization-semantics.md#11-объяснение), D29 | V27 | — | sonnet |
| P4.11 | `GateBridge` (правило выбора панели, режим панели, одно слово → `null`) | [09 §7](09-authorization-semantics.md#7-gate-laravel), D26 | V28–V30 | P03, P09 | opus |
| P4.12 | `Visibility` + `ContextAware::scopeVisibleTo` | [09 §10](09-authorization-semantics.md#10-видимость-visibleto), D31 | V31–V33 | P04a–c | opus |

## F5 — Модель и изменения

| Пункт | Что | Разделы | Готово когда | Probe | Модель |
|---|---|---|---|---|---|
| P5.1 | Трейт `HasAzGuard`, `SubjectAccess`, `SubjectPanels`, контракт `AzGuardSubject` — свои имена D57 | [05 §1](05-php-api.md#1-модель-трейт-hasazguard), D09, D10 | V70; рецепты «Если вы пришли из Spatie» зелёные | — | opus |
| P5.2 | Пайплайн изменений: `Change`, `ChangeResult`, проверка данных, pipes `changing` (`Illuminate\Pipeline`), запись писателем, события после commit | [06 §5](06-extension-points.md#5-хуки-изменений-pipes-и-события), D22, D49 | каждая операция: транзакция, новая версия, повтор без события | P13 | opus |
| P5.3 | `RoleCatalog` read-only, scoped GrantManager editing/FormerKeys grant migration, expectedFingerprint; `PermissionManager` для динамических прав | [05 §2](05-php-api.md#2-панель-azguardpanel), D14, D52 | V71, V82 | — | sonnet |
| P5.4 | События после commit; плагин `azguard/audit` | [08 §6](08-data-model-and-migration.md#6-каталог-событий), D28 | V34–V35 | P11 | sonnet |
| P5.5 | Схема панели `PanelSchema` (+ `toArray()`) | [05 §8](05-php-api.md#8-схема-панели), D54 | V72; JSON-снимок фикстуры | — | opus |

## F6 — Laravel-поверхность и удаление старого

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P6.1 | Фасад, `PanelAccess`, `AzGuard::actingAs()` | [05 §2–§3](05-php-api.md#3-фасад), D09 | рецепты README зелёные | sonnet |
| P6.2 | Middleware `azguard.panel` (вход, право входа, текущая панель, `Context` для очередей, сущность), `azguard.can`; `#[CheckPermission]` — Laravel13 `#[Middleware]` adapter и отдельный 11/12 adapter, `#[SkipPermissionCheck]`, `requireRouteChecks()`; удаление старых middleware и директив `@az*` | D32 | V73, V83; снимок alias'ов | sonnet |
| P6.3 | `config/azguard.php`, `AzGuardConfig`, проверки при загрузке | [07](07-configuration.md), D33 | V36 | sonnet |
| P6.4 | Doctor: проверки на панель и хранилище, проверки плагинов | [12 §2](12-operations-and-release.md#2-doctor-проверки) | `azguard:doctor --json` снимок | sonnet |
| P6.5 | `azguard:install` | D40 | V37 | sonnet |
| P6.6 | Команды | [12 §1](12-operations-and-release.md#1-команды), D38 | снимок команд | sonnet |
| P6.7 | Тестовый kit, `AzGuardFake`, контрактные наборы | D39, [06 §10](06-extension-points.md#10-контрактные-наборы-azguardtestingcontracts) | наборы проходят против встроенных реализаций | sonnet |
| P6.8 | Генераторы `azguard:make:panel\|permission\|policy\|role\|source\|plugin\|restriction\|pipe\|models`, `azguard:stubs` — структура папки D56 | 12 §1, D56, D72, D73 | сгенерированный код проходит контрактные наборы; V78 на сгенерированной панели | sonnet |
| P6.9 | Удаление старого: context-пакет, `GrantBuilder`, `HasScopedRoles`, `PolicyAttributeRegistrar`, generated policies, `AbilitiesResolver`, matcher'ы, `NullSafeUniqueIndex`, старые таблицы и конфиг (DTO `…Abilities` остаётся) | D01, D03 | arch: старые имена отсутствуют | sonnet |
| P6.10 | Механизмы Laravel: `optimizes()` для кэша каталога, `AboutCommand::add()`, панель в `Context` для очередей | D58 | V84; снимок `about` | sonnet |

## F7 — Filament

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P7.1 | Плагин без глобального конфига; `guardPanel()`, `manages()`; права ресурсов — источником `FilamentSource` | [11 §2](11-filament.md#2-плагин) | V24, V75 | sonnet |
| P7.2 | `FilamentGate`, ключи по slug, страницы и виджеты закрыты без права | 11 §3–§4 | V25; уникальность ключей | sonnet |
| P7.3 | `RoleResource` по схеме панели; `PermissionResource` для динамических прав | 11 §5.1, §5.3 | V23, V61, V76 | opus |
| P7.4 | `RoleGrantResource`, `PermissionGrantResource`, выбор панели, поиск субъектов и сущностей, «Почему?» | 11 §5.2 | V26, V63 | sonnet |
| P7.5 | Свои поля из схемы, `FilamentFormExtension` | 11 §6 | V62 | opus |
| P7.6 | `PanelsPage`, `DoctorPage` | 11 §5.4 | снимок страниц | sonnet |
| P7.7 | `azguard:filament:generate` | 11 §7 | снимок генерации | sonnet |

## F8 — Интеграции, документация, выпуски

| Пункт | Что | Разделы | Готово когда | Модель |
|---|---|---|---|---|
| P8.1 | `IntegrationContractTests`, пример интеграции `fixtures/example-integration` в CI | [10 §6–§7](10-integrations.md#6-проверка-интеграции), D51 | V58–V60 | opus |
| P8.2 | Документация и рецепты ([12 §6](12-operations-and-release.md#6-документация)) | D42 | рецепты = сниппеты | sonnet |
| P8.3 | Гейты совместимости (manifest, снимки, пример интеграции) | 12 §5, D41 | гейты в CI | sonnet |
| P8.4 | Consumer-фикстуры (a)–(d) и матрица | 12 §4 | зелёная матрица | sonnet |
| P8.5 | Выпуск **1.0.0-beta.1** + abandoned-пометки старых пакетов | D01, D03 | тег; анонс | sonnet |
| P8.6 | Передать идею моста и заметки V1–V7 в план Vaulter | [10 §9–§10](10-integrations.md#9-идея-моста-vaulter--azguard) | задача в плане Vaulter создана | sonnet |
| P8.7 | Реальная CRM fixture app + independent consumer R01–R68; кабинет/админка/API/модуль, configured contexts, policies/code roles, plugins/UI/SQL/workers/concurrency; внешнее ревью API | D01, D79, [17](17-crm-acceptance-tests.md), [18](18-contexts-and-runtime-inputs.md) | все обязательные cases на announced matrix; actual report/limits; V108–V116 | opus (ревью) |
| P8.8 | **1.0.0**: Roave BC Check включён | D41 | все гейты блокирующие | sonnet |

## Зависимости

```
F0 ─► F1 ─► F2 ─► F3 ─► F4 ─► F5 ─► F6 ─► F7 ─► F8
            │           │
            │           └── P4.1 (пайплайн) нужен до всех источников P4.2–P4.5
            └── P2.7 (фабрика источников) и P2.8 (папка панели) нужны до P4.2–P4.5; P2.4 (плагины) — до P7.1
P2.2 (выбор панели) нужен до P4.11 (Gate) и P5.1 (трейт)
P4.11 требует P2.5 (O(1)-поиск в каталоге);  P4.12 требует P4.4, P4.5, P4.6
P5.5 (схема) требует P4.2–P4.5 (источники описывают себя через DescribesSchema)
F7 требует P5.1–P5.5;  P8.1 требует F4–F5
```


## Уточнения зависимостей и acceptance пятого прохода

P1.6 обязателен до P2.2/P3.2/P4.1. Tenant/context columns/grant identities/origin из 08 входят в **P3.2**,
а не добавляются миграцией после реализации grants. P3.1 теперь state-first locks/root commit protocol;
P4.1 — RoleContribution, per-grant conditions, all-before deny precedence; P4.6 — TenantPolicy/ContextDefinition
и resource mismatch; P4.8/P4.9 — validated fence, expiry/incarnation, tokens map; P4.12 — exact visibility adapters.
P5.2/P5.3 используют final validation/GrantManager scoped reads, code role/context schema + DB grant assignments.
P7.2–P7.5 включают tenant-target authorization всех surfaces, а не только главной RoleResource формы.

| Owning item | Дополнительное обязательное acceptance |
|---|---|
| P2.2/P2.4/P2.7 | V86, V102: hint conflicts, prefix references, runtime factory scope |
| P3.1/P3.2/P3.4 | V97, V99, V101, V105: сериализация/DDL/strict writes |
| P4.1–P4.7 | V88–V94: boundaries, role contexts, пустой SuperAdmin, errors, token cap |
| P4.8/P4.9 | V99–V100: version fence/expiry/restore; budgets D44 |
| P4.12 — приёмка | V95, включая predicate equivalence, pagination/total и unsupported/cross-connection |
| P5.1–P5.5 | V87, V89, V91–V92, V97, V104: immutable wrappers, scoped catalog/managers/events/schema |
| P6.2/P6.4/P6.8/P6.10 | V103–V105: lifecycle, diagnostics, version adapter |
| P7.2–P7.5 | V95–V96, V102: весь UI включая search/attach/export, не только меню |
| P8.1/P8.4/P8.7 | V98/V105; CRM fixture A/B + external origins; поддержанные engines/runtime |

Это остаётся design workstreams, не запущенный executable plan. Модель/effort для будущих items выбирается
по актуальной среде исполнения; названия opus/sonnet здесь — историческая маршрутизация исходного досье.


Утверждённый layout D72: Permissions/<Group>, параллельные Policies/Queries/Abilities, механизмы в корне; generators/discovery/module
fixtures и V106 входят в P2.8/P6.8/P8.4. Подробное различие Users/Projects как resource/subject/context —
[00 §13](00-overview.md#13-почему-permissionsusers-а-не-users-в-корне).

## Дополнение: контексты и реальная CRM-приёмка

P1.6/P2.1/P2.4/P2.7/P2.8/P4.1/P4.6/P4.8/P4.12/P5.3/P5.5/P6.8/P7.4/P8.4 учитывают D74–D79:
configured objects/filter contracts, target subject/BaseRole/actor inputs, common AND role-branch filters,
phase-aware assignment/revoke, exact set queries/cache recipes, fresh plugin factory/options/DI, guard selector.
Конкретные cases и owning contract surfaces — [17](17-crm-acceptance-tests.md), [18](18-contexts-and-runtime-inputs.md).

P8.7 поднимает fixture рано параллельно owning capabilities, каждый implementation slice добавляет реальные cases;
финальная qualification после F7/F8 supported adapters, генераторов/consumer fixtures. Readiness evidence хранится
отдельно от плана cases; ошибки исправляются в owning implementation items, а не маскируются ожидаемым deny.


D80–D83 уточняют все owning items: no DB role definitions, typed plugin factories/filters/fields,
explicit per-permission authority dispatcher, BeforeResult, CodeStateToken vs StateToken, mode-aware UI/query.
P1.6 включает эти values; P2.4 не вводит inherited make; P2.5 validates modes/ownership; P4.1/P4.3 enforce authority;
P4.8 batches only consumed dependencies; P5.3 edits assignments; P6.8 generated consumers and P8.7 real cases
проходят V117–V120 и process map [20](20-process-map.md). Никаких SQL role/profile CRUD items в 1.0.
