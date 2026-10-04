<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-10-04
inputs_sha256: ad205b24e7bebfd284bcaa757ace6a569dedab858d02d68f8e8f24240d87c4b7
generated_by: task plan-views status
-->

# Plan status

> Do not manipulate by hand: this is a generated view.

## 0. Meta

| Field | Meaning |
|:--|:--|
| Version | — |
| Status | 🟡 In progress |
| Last Updated | 2026-10-04 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/total | Status |
|:--|:--|:--|:--|
| P0 | Подготовка: legacy freeze, каркас 1.0, регрессионные спецификации | 5/5 | 🟢 Done |
| P1 | Ядро понятий Kernel и arch-правила зон | 7/7 | 🟢 Done |
| P2 | Панели, выбор панели, плагины, каталог, фабрика источников, FolderSource | 9/10 | 🟠 Done with deviations |
| P3 | Хранилище | 5/5 | 🟢 Done |
| P4 | Проверка прав: пайплайн, источники, контексты, кэш, Gate, видимость | 4/23 | 🟡 In progress |
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
| P2.2 | PanelResolver: одно правило выбора панели и префиксы | 🟢 Done | 2026-10-02 |
| P2.3 | Настройки панели и PanelSettings | 🟢 Done | 2026-10-02 |
| P2.4 | Плагины: typed factories, PluginContext, изоляция, конфликты | 🟢 Done | 2026-10-02 |
| P2.5 | Каталог из источников, коллизии, O(1)-поиск, catalog:cache | 🟢 Done | 2026-10-03 |
| P2.6 | Модули: registerPanel, configurePanel, AmbiguousPanelException | 🟢 Done | 2026-10-03 |
| P2.7 | Фабрика источников: Source, возможности, SourceManager, AsSource | 🟢 Done | 2026-10-03 |
| P2.8 | FolderSource: discovery папки панели, атрибуты, pairing политик | 🟢 Done | 2026-10-03 |
| P2.9 | Кадры областей и directories: AssignmentScopeRuntime, LookupContext, BaseAssignmentScope | 🟢 Done | 2026-10-03 |
| P2.10 | Review P2: независимая read-only проверка фазы | 🟠 Done with deviations | 2026-10-03 |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Storage::mutate, блокировки, повторы, версия панели, host_keys | 🟢 Done | 2026-10-03 |
| P3.2 | Миграции с префиксом хранилища на PG/MySQL/MariaDB/SQLite | 🟢 Done | 2026-10-03 |
| P3.3 | Базовые и свои модели, azguardFields, meta, decisionFields | 🟢 Done | 2026-10-04 |
| P3.4 | Защита от прямых записей | 🟢 Done | 2026-10-04 |
| P3.5 | Review P3: независимая read-only проверка фазы | 🟢 Done | 2026-10-04 |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Пайплайн проверки и authority dispatcher | 🟢 Done | 2026-10-04 |
| P4.2 | Выдачи из папки: BaseRole, GrantedAutomatically, GrantedToAll, FormerKeys | 🟢 Done | 2026-10-04 |
| P4.3 | Mode-aware policies, Decides, PolicyFor, GateSource::map | 🟢 Done | 2026-10-04 |
| P4.4 | DatabaseSource: назначения, сроки, dynamicPermissions, писатель | 🟢 Done | 2026-10-04 |
| P4.5 | RelationSource и видимость через связи | ⬜ Not started | — |
| P4.6 | Tenant, AssignmentScope и ресурс в проверке | ⬜ Not started | — |
| P4.7 | Суперадмин — признак роли | ⬜ Not started | — |
| P4.8 | Кэш наборов прав, StateToken, version fence, expiry | ⬜ Not started | — |
| P4.9 | decideMany | ⬜ Not started | — |
| P4.10 | explain и azguard:explain | ⬜ Not started | — |
| P4.11 | GateBridge | ⬜ Not started | — |
| P4.12 | Visibility и exact query adapters | ⬜ Not started | — |
| P4.13 | Срез-review P4.1–P4.7: независимая read-only проверка границы решения | ⬜ Not started | — |
| P4.14 | Review P4: независимая read-only проверка фазы | ⬜ Not started | — |
| P4.15 | CRM-фикстура и R-кейсы границы решения P4.1–P4.7 | ⬜ Not started | — |
| P4.16 | Генеративная квалификация движка и scope | ⬜ Not started | — |
| P4.17 | Dynamic Prepare и единый authority fence | ⬜ Not started | — |
| P4.18 | Scoped source integration и регрессия P08 | ⬜ Not started | — |
| P4.19 | Context eligibility: native filters и external adapters | ⬜ Not started | — |
| P4.20 | Cache transactions, incarnation и touch | ⬜ Not started | — |
| P4.21 | Квалификация кэша: гонки, Redis, replica lag, latency | ⬜ Not started | — |
| P4.22 | Exact predicate SPI, partitions и SQL compiler | ⬜ Not started | — |
| P4.23 | Exact visibility: CRM, parity и EXPLAIN | ⬜ Not started | — |

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
| 2026-10-02 | claude | plan-run · in-progress · — |
| 2026-10-02 | claude | plan-run · closed-green · PanelResolver, словарь префиксов, индекс enum, replace() при сборке (D9), arch-правило доказанно краснеет; P01c, P05, P09 covered; PermissionKey::prefixed() не создан по D9; 8 гейтов GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-02 | claude | plan-run · in-progress · — |
| 2026-10-02 | claude | plan-run · closed-green · PanelSettings с происхождением значений, cache/gate/consistency, порядок провайдер → плагины → configurePanels → defaults, PluginConflictException, проверки enum и ttl при сборке; V47 на записях рецепта; 8 гейтов GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-02 | claude | plan-run · in-progress · — |
| 2026-10-02 | claude | plan-run · closed-green · note abbreviated; full text: journal.jsonl:41 |
| 2026-10-02 | claude | plan-run · in-progress · — |
| 2026-10-03 | claude | plan-run · closed-green · Каталог из источников, коллизии, RoleCompiler, привязки политик, O(1)-индексы, withDynamic, catalog:cache|clear, prefix_conflict, владение по has(); V08, V81, V119 (части), D7 п.4; RED arch; 8 гейтов GREEN (findings P2.5)
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | grok | plan-run · in-progress · — |
| 2026-10-03 | grok-4.7/high | plan-run · closed-green · Фасад AzGuard и AzGuardManager, модульные фикстуры Blog/Shop; V55 и V07 сквозь фасад; дефектов интеграции P2.1–P2.5 нет; 8 гейтов GREEN (findings P2.6)
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | grok | plan-run · in-progress · — |
| 2026-10-03 | grok-4.7/high | plan-run · closed-green · SourceManager, AsSource, контракты возможностей и EvaluationContext; именованные источники и один писатель; V77, V102 (часть), V107 (часть), P12; RED transaction(); 8 гейтов GREEN (findings P2.7)
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | grok | plan-run · in-progress · — |
| 2026-10-03 | grok-4.7/high | plan-run · closed-green · FolderSource и PanelDiscovery: папка панели наполняет каталог, пары политик по #[Decides], V78/V79/V102/V106; кэш discovery без повторного разбора; RED Policies↛Storage/Changes; 8 гейтов GREEN (findings P2.8)
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | grok | plan-run · in-progress · — |
| 2026-10-03 | grok-4.7/high | plan-run · closed-green · Кадры областей, SPI directories и BaseAssignmentScope; resolve() — один SELECT; 8 гейтов GREEN
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex | plan-run · in-progress · — |
| 2026-10-03 | codex | plan-run · closed-deviations · Review P2 RED: R1–R3 major, R4 minor; 6 mandatory gates GREEN. Read-only report complete; owning repairs P2.5/P2.8 pending. findings/P2-review.md |
| 2026-10-03 | codex | plan-run · no-op · write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | codex | plan-run · in-progress · — |
| 2026-10-03 | codex | plan-run · closed-green · R1 repaired; all 8 gates GREEN; P2 RED, R2/R3 open → separate P2.5. findings/P2-owning-repairs.md. write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | Codex | plan-run · in-progress · — |
| 2026-10-03 | Codex | plan-run · closed-green · R2/R3 repaired; R1 probes GREEN, R4 pre-repeat sync verified; all 8 item gates GREEN. P2 closure awaits manual agreement; P3 not started. findings/P2-owning-repairs.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | Codex | plan-close · closed-green · P2 GREEN: R1/R2/R3 закрыты owning repairs P2.8/P2.5; R4 синхронизирован по D10. Исторический RED review/P2.10 сохранён. closure/P2-findings.json проверен. P3 не начат. write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | Codex | plan-close · in-progress · Checking phase-close admission. |
| 2026-10-03 | Codex | plan-close · closed-green · P2 GREEN admission reconciled with actual native session/run identity; prior phase event retained. R1–R4 resolved, historical review preserved, P3 not started. write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | plan-designer/claude-opus-5-5 | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-03 | implementer / gpt-6.1-sol | plan-run · in-progress · — |
| 2026-10-03 | implementer / gpt-6.1-sol | plan-run · closed-green · GREEN: findings/P3-execution.md; artifacts/P3-execution/P3.1-*; PG/MySQL engines 3/3, full 1364, types 99.8%.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-03 | implementer / gpt-6.1-sol | plan-run · in-progress · — |
| 2026-10-03 | implementer / gpt-6.1-sol | plan-run · closed-green · GREEN: findings/P3-execution.md; artifacts/P3-execution/P3.2-*; 4 DDL fixtures, engines PG/MySQL/MariaDB 5/5, full 1380, types 99.8%; D13 VARBINARY.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex:gpt-6.1-sol:high | plan-run · in-progress · — |
| 2026-10-04 | codex:gpt-6.1-sol:high | plan-run · closed-green · GREEN: findings/P3-execution.md; artifacts/P3-models/. Targeted 404, suite 1434, types 99.8%, engines all GREEN.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex:gpt-6.1-sol:high | plan-run · in-progress · — |
| 2026-10-04 | codex:gpt-6.1-sol:high | plan-run · closed-green · GREEN: findings/P3-execution.md; artifacts/P3-direct-writes/. Suite 2029; engines GREEN; Grok 4.7 R1 fixed, delta GREEN.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex | plan-run · in-progress · — |
| 2026-10-04 | codex:gpt-6.1-sol:high | plan-run · closed-green · Review P3 GREEN: Grok 4.7/high/500k, no blocker/major; R2 minor PK names owned by P3.2. Initial R1 false positive disproved by live 14-assertion reproduction. All 10 declared gates GREEN; findings/P3-review.md.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex | plan-close · in-progress · Checking phase-close admission. |
| 2026-10-04 | codex | plan-close · closed-green · P3 GREEN: all product gates and independent Grok 4.7/high/500k review; no blocker/major. R2 minor native PK names retained in findings/P3-review.md, owning P3.2; read-only boundary preserved.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | plan-designer/claude-opus-5-5 | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | codex | plan-run · in-progress · — |
| 2026-10-04 | codex-p4-design-repair | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | codex-p4-design-repair | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | codex-p4-design-repair | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | codex-p4-design-repair | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | codex-p4-design-repair | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | codex | plan-run · closed-green · GREEN: findings/P4-execution.md; all eight Validation carriers; comprehensive Grok 4.7/high/500k review + root verification.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex:p4-grouping:model-effort-unattested | plan-design · no-op · Design finalized; execution has not started. |
| 2026-10-04 | codex | plan-run · in-progress · — |
| 2026-10-04 | codex | plan-run · closed-green · GREEN: findings/P4-execution.md; declared Validation and independent Grok 4.7/high/500k review.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex | plan-run · in-progress · — |
| 2026-10-04 | codex | plan-run · closed-green · GREEN: findings/P4-execution.md; declared Validation and independent Grok 4.7/high/500k review.
write-site: pre_mutation: allow · pre_final: allow |
| 2026-10-04 | codex | plan-run · in-progress · — |
| 2026-10-04 | codex | plan-run · closed-green · GREEN: findings/P4-execution.md; raw DB source/fence/selection/own. All declared validation on stable candidate, independent Grok 4.7/high/500k.
write-site: pre_mutation: allow · pre_final: allow |

## Owner Gates

- P2.10: known deviation
