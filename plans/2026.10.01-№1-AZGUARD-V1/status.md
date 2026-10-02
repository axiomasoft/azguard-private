<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-10-02
inputs_sha256: e1ebb03009228ba18f90edc1ad8180ef61a863e5846f643afd53bdbd98207267
generated_by: task plan-views status
-->

# Plan status

> Do not manipulate by hand: this is a generated view.

## 0. Meta

| Field | Meaning |
|:--|:--|
| Version | — |
| Status | 🟡 In progress |
| Last Updated | 2026-10-02 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/total | Status |
|:--|:--|:--|:--|
| P0 | Подготовка: legacy freeze, каркас 1.0, регрессионные спецификации | 5/5 | 🟢 Done |
| P1 | Ядро понятий Kernel и arch-правила зон | 7/7 | 🟢 Done |
| P2 | Панели, выбор панели, плагины, каталог, фабрика источников, FolderSource | 1/10 | 🟡 In progress |
| P3 | Хранилище | 0/4 | ⬜ Not started |
| P4 | Проверка прав: пайплайн, источники, контексты, кэш, Gate, видимость | 0/12 | ⬜ Not started |
| P5 | Модель и изменения | 0/5 | ⬜ Not started |
| P6 | Laravel-поверхность и удаление legacy | 0/10 | ⬜ Not started |
| P7 | Filament по схеме панели | 0/7 | ⬜ Not started |
| P8 | Интеграции, документация, гейты, CRM-приёмка, выпуски | 0/8 | ⬜ Not started |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.1 | ADR «Ecosystem conventions» в docs/adr | 🟢 Done | 2026-10-01 |
| P0.2 | Probes 0.3 → спецификации регрессионных тестов с owning items | 🟢 Done | 2026-10-01 |
| P0.3 | Каркас consumer-фикстуры из собранных архивов в CI | 🟢 Done | 2026-10-01 |
| P0.4 | Composer-имена axiomasoft/*, homepage, удаление context-пакета из сборки | 🟢 Done | 2026-10-01 |
| P0.5 | Legacy freeze и каркас пакетов 1.0 с зелёными гейтами | 🟢 Done | 2026-10-01 |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Kernel\Identity: ключи, ссылки, IdentityCodec | 🟢 Done | 2026-10-01 |
| P1.2 | Грамматика имён и шаблонов | 🟢 Done | 2026-10-01 |
| P1.3 | Значения решения: AccessRequest, Decision, Grant, токены, PermissionSet | 🟢 Done | 2026-10-01 |
| P1.4 | Arch-правила зон | 🟢 Done | 2026-10-01 |
| P1.6 | Authority semantics, кодовые роли и структурные SPI областей | 🟢 Done | 2026-10-01 |
| P1.7 | Review P1: независимая read-only проверка фазы | 🟢 Done | 2026-10-02 |
| P1.8 | Исправление находок Review P1 | 🟢 Done | 2026-10-02 |

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | PanelProvider, PanelBuilder, Panel, PanelRegistry, CurrentPanel | 🟢 Done | 2026-10-02 |
| P2.2 | PanelResolver: одно правило выбора панели и префиксы | ⬜ Not started | — |
| P2.3 | Настройки панели и PanelSettings | ⬜ Not started | — |
| P2.4 | Плагины: typed factories, PluginContext, изоляция, конфликты | ⬜ Not started | — |
| P2.5 | Каталог из источников, коллизии, O(1)-поиск, catalog:cache | ⬜ Not started | — |
| P2.6 | Модули: registerPanel, configurePanel, AmbiguousPanelException | ⬜ Not started | — |
| P2.7 | Фабрика источников: Source, возможности, SourceManager, AsSource | ⬜ Not started | — |
| P2.8 | FolderSource: discovery папки панели, атрибуты, pairing политик | ⬜ Not started | — |
| P2.9 | Кадры областей и directories: AssignmentScopeRuntime, LookupContext, BaseAssignmentScope | ⬜ Not started | — |
| P2.10 | Review P2: независимая read-only проверка фазы | ⬜ Not started | — |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Storage::mutate, блокировки, повторы, версия панели, host_keys | ⬜ Not started | — |
| P3.2 | Миграции с префиксом хранилища на PG/MySQL/MariaDB/SQLite | ⬜ Not started | — |
| P3.3 | Базовые и свои модели, azguardFields, meta, decisionFields | ⬜ Not started | — |
| P3.4 | Защита от прямых записей | ⬜ Not started | — |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Пайплайн проверки и authority dispatcher | ⬜ Not started | — |
| P4.2 | Выдачи из папки: BaseRole, GrantedAutomatically, GrantedToAll, FormerKeys | ⬜ Not started | — |
| P4.3 | Mode-aware policies, Decides, PolicyFor, GateSource::map | ⬜ Not started | — |
| P4.4 | DatabaseSource: назначения, сроки, dynamicPermissions, писатель | ⬜ Not started | — |
| P4.5 | RelationSource и видимость через связи | ⬜ Not started | — |
| P4.6 | Tenant, AssignmentScope и ресурс в проверке | ⬜ Not started | — |
| P4.7 | Суперадмин — признак роли | ⬜ Not started | — |
| P4.8 | Кэш наборов прав, StateToken, version fence, expiry | ⬜ Not started | — |
| P4.9 | decideMany | ⬜ Not started | — |
| P4.10 | explain и azguard:explain | ⬜ Not started | — |
| P4.11 | GateBridge | ⬜ Not started | — |
| P4.12 | Visibility и exact query adapters | ⬜ Not started | — |

## Phase P5

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P5.1 | Трейт HasAzGuard, SubjectAccess, SubjectPanels | ⬜ Not started | — |
| P5.2 | Пайплайн изменений: Change, pipes, писатель, события после commit | ⬜ Not started | — |
| P5.3 | RoleCatalog, scoped GrantManager, PermissionManager | ⬜ Not started | — |
| P5.4 | События после commit и плагин audit | ⬜ Not started | — |
| P5.5 | PanelSchema | ⬜ Not started | — |

## Phase P6

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P6.1 | Фасад, PanelAccess, actingAs | ⬜ Not started | — |
| P6.2 | Middleware azguard.panel/azguard.can и CheckPermission | ⬜ Not started | — |
| P6.3 | config/azguard.php и AzGuardConfig | ⬜ Not started | — |
| P6.4 | Doctor | ⬜ Not started | — |
| P6.5 | azguard:install | ⬜ Not started | — |
| P6.6 | Команды | ⬜ Not started | — |
| P6.7 | Тестовый kit, AzGuardFake, контрактные наборы | ⬜ Not started | — |
| P6.8 | Генераторы и stubs по структуре D56/D72 | ⬜ Not started | — |
| P6.9 | Удаление legacy/0.3 и старых имён | ⬜ Not started | — |
| P6.10 | optimizes, about, панель в Context для очередей | ⬜ Not started | — |

## Phase P7

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P7.1 | Filament-плагин, guardPanel, FilamentSource | ⬜ Not started | — |
| P7.2 | FilamentGate и ключи по slug | ⬜ Not started | — |
| P7.3 | RoleResource и PermissionResource по схеме | ⬜ Not started | — |
| P7.4 | RoleGrantResource, PermissionGrantResource, «Почему?» | ⬜ Not started | — |
| P7.5 | Свои поля из схемы, FilamentFormExtension | ⬜ Not started | — |
| P7.6 | PanelsPage, DoctorPage | ⬜ Not started | — |
| P7.7 | azguard:filament:generate | ⬜ Not started | — |

## Phase P8

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P8.1 | IntegrationContractTests и пример интеграции | ⬜ Not started | — |
| P8.2 | Документация и рецепты | ⬜ Not started | — |
| P8.3 | Гейты совместимости | ⬜ Not started | — |
| P8.4 | Consumer-фикстуры (a)–(d) и матрица | ⬜ Not started | — |
| P8.5 | Выпуск 1.0.0-beta.1 и abandoned-пометки | ⬜ Not started | — |
| P8.6 | Передача идеи моста и заметок V1–V7 в план Vaulter | ⬜ Not started | — |
| P8.7 | CRM fixture app и независимая квалификация R01–R68 | ⬜ Not started | — |
| P8.8 | Выпуск 1.0.0 с Roave BC Check | ⬜ Not started | — |

## 6. Update Log

| Date | Who (role/model) | What |
|:--|:--|:--|
| 2026-10-01 | claude-opus-5-5/unknown | plan-design · no-op · Верхний слой: P0–P8 скелеты, решения D1–D5; исполнение не начато. |
| 2026-10-01 | claude-opus-5-5/unknown | plan-design · no-op · P0 детализирована: P0.1–P0.5, порядок Planned Items; исполнение требует owner-вызова plan-run. |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · ADR EN/RU; parity-gate GREEN; починен кириллический дрейф в docs/reference.md |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · blocked · Каркас 1.0 готов и закоммичен (34650da); ждёт доступ к CHANGELOG.md |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · Каркас 1.0 и legacy/0.3; гейты GREEN; CHANGELOG записан |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · axiomasoft/* метаданные, split/release на два пакета; GREEN |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · 19 spec-файлов регрессий, README и meta-тест; GREEN |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · consumer-fixture.sh, README и non-blocking CI job; прогоны GREEN |
| 2026-10-01 | claude | plan-close · closed-green · P0.1-P0.5 GREEN; inline phase close |
| 2026-10-01 | plan-designer/claude-opus-5-5 | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · PermissionGrammar, PatternMatcher, база исключений D37; P14 covered; гейты GREEN |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · Kernel\Identity и IdentityCodec; V01/V02/V04 property, P07 covered, RED-доказательство; гейты GREEN |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · Kernel\Decision и PermissionSet; инвариант effect/reason 63 строки; гейты GREEN |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · arch-правила зон, конвенции исходников, api-manifest; 8 RED-доказательств, найдены вакуумные формы Pest arch; гейты GREEN |
| 2026-10-01 | claude | plan-run · in-progress · — |
| 2026-10-01 | claude | plan-run · closed-green · authority semantics, BaseRole с атрибутами, SuperAdminRole, SPI областей/субъектов, AssignmentScopePhase; манифест +19 классов; 8 гейтов GREEN; найден тихий пропуск файлов в type-coverage |
| 2026-10-02 | claude | plan-run · in-progress · — |
| 2026-10-02 | claude | plan-run · closed-green · review P1: verdict RED — F1 major (baseline debug-call arch rule vacuous, owner P1.4), F2/F3 minor (P1.3, P1.6); 6 гейтов GREEN; RED-доказательство P1.4 повторено в scratch |
| 2026-10-02 | claude | plan-run · in-progress · — |
| 2026-10-02 | claude | plan-run · closed-green · F1–F3 Review P1 исправлены: SourceScan (одна реализация token-скана), debug-правило краснеет; ConsistencyException/InvalidSourceContributionException по D7; Phase Context P2 принимает от P1.6; 8 гейтов GREEN (type-coverage при memory_limit=1G) |
| 2026-10-02 | claude | plan-close · closed-green · P1.1–P1.8 GREEN; Review P1 RED → F1–F3 исправлены в P1.8, повторная проверка GREEN; inline phase close |
| 2026-10-02 | claude | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-02 | claude | plan-run · in-progress · — |
| 2026-10-02 | claude | plan-run · closed-green · Панель, builder с происхождением записей, реестр с заморозкой на booted, CurrentPanel, config/azguard.php и AzGuardConfig; V05, V06, V107/V108 (части) покрыты; arch-правила Panels и Configuration доказанно краснеют; 8 гейтов GREEN |
| 2026-10-02 | claude | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |

## Owner Gates

- —
