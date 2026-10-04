# Execution Roadmap - 2026.10.01-№1-AZGUARD-V1

<!-- execution-sheet/v1 -->

**Updated:** 2026-10-04 · **Corresponds to plan.md:** верхний слой + детализация P0, P1, P2, P3, P4

## Grouping rule

- Фаза детализируется и исполняется целиком перед следующей (D3); строки добавляются по мере детализации.
- Batch не пересекает фазу; launch использует максимум class/effort/review своих пунктов.
- Owner-gated пункты P8.5/P8.6/P8.8 — всегда `solo`.
- Routing-батч — сплошной диапазон номеров фазы, исполняется в порядке номеров: это требование текущего Task preflight.
- Общий порядок P4 задаёт execution sheet/D15; непоследовательные по номерам пары P4.17 → P4.5, P4.19 → P4.7, P4.16 → P4.18 — solo-продолжения с отдельным допуском, а не Routing-батчи (D16).
- Каждый item сохраняет собственные Validation/Deliverables и закрывается до начала consumer; контекст повторно используется только внутри авторизованного scope и при допуске continuity reducer.

## Execution sheet - the only source of launch commands

| Command | Unified route | Review |
|:--|:--|:--|
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P0.1 P0.2 P0.3 P0.4 P0.5` | `implementation/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P1.1 P1.2 P1.3 P1.4` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P1.6` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P1.7` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P1.8` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.1 P2.2 P2.3` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.4` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.6` | `implementation/medium` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.7` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.9` | `implementation/medium` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.8` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.5` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P2.10` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P3.1 P3.2` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P3.3 P3.4` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P3.5` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.1` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.2 P4.3` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.4` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.17` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.5` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.6` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.19` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.7` | `implementation/medium` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.16` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.18` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.15` | `implementation/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.13` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.8` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.20` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.21` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.9` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.10 P4.11` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.22` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.12` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.23` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.14` | `frontier/high` | `none` |

## Execution card

| Item | Batch | Launch | Model class/Effort | Owner's Gate | Note |
|---|---|---|---|---|---|
| P0.5 | B0 | plan-run series | implementation/high | — | legacy freeze и каркас — вход всех пунктов P0 |
| P0.4 | B0 | ↑ | implementation/medium | — | метаданные поверх каркаса |
| P0.2 | B0 | ↑ | implementation/medium | — | спецификации регрессий |
| P0.3 | B0 | ↑ | implementation/medium | — | consumer fixture поверх архивов P0.4 |
| P0.1 | B0 | ↑ | implementation/medium | — | ADR, независим |
| P1.2 | B1 | plan-run series | implementation/medium | — | грамматика и база исключений — вход всех значений |
| P1.1 | B1 | ↑ | frontier/high | — | ссылки и IdentityCodec; регрессия P07 |
| P1.3 | B1 | ↑ | implementation/medium | — | значения решения поверх P1.1 |
| P1.4 | B1 | ↑ | implementation/medium | — | arch-правила зон и api-manifest |
| P1.6 | solo | plan-run | frontier/high | — | authority semantics, BaseRole, SPI областей (D6) |
| P1.7 | solo | plan-run | frontier/high | — | Review P1, read-only verdict |
| P1.8 | solo | plan-run | frontier/high | — | исправление F1–F3 Review P1 (D7) |
| P2.1 | B2a | plan-run series | frontier/high | — | панель, builder, реестр, минимальный конфиг |
| P2.2 | B2a | ↑ | frontier/high | — | PanelResolver, префиксы; регрессии P01c, P05, P09 |
| P2.3 | B2a | ↑ | frontier/high | — | порядок настроек, PanelSettings |
| P2.4 | solo | plan-run series | frontier/high | — | плагины, PluginContext, build id |
| P2.5 | solo | plan-run | frontier/high | — | контракты каталога, каталог, компиляция ролей, catalog:cache |
| P2.6 | solo | ↑ | implementation/medium | — | фасад панелей, модульные фикстуры |
| P2.7 | solo | plan-run series | frontier/high | — | фабрика источников, SourceManager, писатель |
| P2.8 | solo | plan-run | frontier/high | — | FolderSource, discovery, pairing политик |
| P2.9 | solo | ↑ | implementation/medium | — | кадры областей и directories (D6) |
| P2.10 | solo | plan-run | frontier/high | — | Review P2, read-only verdict, свежая сессия |
| P3.1 | B3a | plan-run series | frontier/high | — | хранилища, mutate, блокировки, повторы, panel_state; engines PG/MySQL |
| P3.2 | B3a | ↑ | frontier/high | — | схема 08 §2, storage_state, миграции, MariaDB; DDL на 4 СУБД |
| P3.3 | B3b | plan-run series | frontier/high | — | модели, свои модели, Field/FieldTarget, GrantFields |
| P3.4 | B3b | ↑ | implementation/medium | — | запрет прямых записей, arch-правила записи |
| P3.5 | solo | plan-run | frontier/high | — | Review P3, read-only verdict, свежая сессия |
| P4.1 | solo | plan-run | frontier/high | — | Пайплайн проверки и authority dispatcher (D15) |
| P4.2 | B4a | plan-run series | frontier/high | — | recommended: каталог, роли и фикстуры вместе с P4.3; отдельные item gates (D16) |
| P4.3 | B4a | plan-run series | frontier/high | — | recommended: политики и canonical binding на том же каталоге P4.2; отдельные item gates (D16) |
| P4.4 | solo | plan-run | frontier/high | — | отдельный допуск: Storage/SPI, raw DB reads и fence на трёх engines (D15/D16) |
| P4.17 | solo | plan-run | frontier/high | — | отдельный допуск coherent Prepare; затем переиспользовать root для P4.5 в разрешённом scope (D16) |
| P4.5 | solo | plan-run | frontier/high | — | отдельный допуск RelationSource; контекст P4.17 переиспользуется, numeric batch невозможен (D16) |
| P4.6 | solo | plan-run | frontier/high | — | отдельный допуск: структурная tenant/scope/owner граница и конфигурация (D15/D16) |
| P4.19 | solo | plan-run | frontier/high | — | отдельный допуск eligibility/adapters; затем root continuation P4.7 в разрешённом scope (D16) |
| P4.7 | solo | plan-run | implementation/medium | — | отдельный допуск admin semantics; переиспользовать контекст P4.19, numeric batch невозможен (D16) |
| P4.16 | solo | plan-run | frontier/high | — | отдельный допуск property oracle; затем root continuation P4.18 в разрешённом scope (D16) |
| P4.18 | solo | plan-run | frontier/high | — | отдельный допуск scoped integration/P08 и трёх engines; reuse P4.16, numeric batch невозможен (D16) |
| P4.15 | solo | plan-run | implementation/high | — | отдельный допуск: полноценная CRM-фикстура, discovery и ранняя acceptance matrix (D15/D16) |
| P4.13 | solo | plan-run | frontier/high | — | независимый Grok 4.7/high read-only срез-review перед cache; граница батчей (D16) |
| P4.8 | solo | plan-run | frontier/high | — | отдельный допуск: raw cache, key/refresh/expiry и lifecycle (D15/D16) |
| P4.20 | solo | plan-run | frontier/high | — | отдельный допуск: root transactions, marker, incarnation/touch и commit publication (D15/D16) |
| P4.21 | solo | plan-run | frontier/high | — | отдельный допуск: concurrency, Redis, real replica lag и benchmarks (D15/D16) |
| P4.9 | solo | plan-run | frontier/high | — | отдельный допуск: set-based batch engine, общий fence и budgets на engines (D15/D16) |
| P4.10 | B4e | plan-run series | implementation/medium | — | recommended: explain и Gate P4.11 используют ScenarioGenerator, resolver и паритет (D16) |
| P4.11 | B4e | plan-run series | frontier/high | — | recommended: GateBridge на том же сценарном корпусе P4.10; отдельные item gates (D16) |
| P4.22 | solo | plan-run | frontier/high | — | отдельный допуск: публичный predicate SPI, partitions и SQL compiler (D15/D16) |
| P4.12 | solo | plan-run | frontier/high | — | отдельный допуск: exact visibility integration, unsupported/cross-connection bounds (D15/D16) |
| P4.23 | solo | plan-run | frontier/high | — | отдельный допуск: CRM/parity, scale и настоящий SQL EXPLAIN (D15/D16) |
| P4.14 | solo | plan-run | frontier/high | — | независимый Grok 4.7/high read-only Review P4 после P4.23; граница фазы (D16) |

## P4 execution profile (D16)

| Field | Value |
|---|---|
| Executor | `gpt-6.1-sol/high`; cwd `/home/vostrikov/projects/packages/azguard`, `-a never`, `-s danger-full-access` |
| Reviewer | `grok/grok-4.7/high`, независимая read-only сессия |
| Implementation selector | К выбранной базовой команде execution sheet добавить `--reviewer grok/grok-4.7 --review-effort high`; передать selector в каждый item `prepare` |
| Mandatory review boundaries | P4.13 перед P4.8; P4.14 после P4.23; reviewer verdict не заменяется self-check исполнителя |
| Scope | Реализация этой design-правкой не запускается; P4.1 GREEN, не повторять |

B4a = P4.2 → P4.3; B4e = P4.10 → P4.11. Это рекомендованные группы, ready subset допускается runtime.
Три последовательности ниже переиспользуют root-контекст, но сохраняют отдельные строки запуска:

| Последовательность | Почему одна сессия полезна | Допуск |
|---|---|---|
| P4.17 → P4.5 | Source capabilities/selection, 9 общих Required Reads | Отдельный prepare каждого item после GREEN predecessor |
| P4.19 → P4.7 | Eligibility и admin contributions, 8 общих Required Reads | Отдельный prepare каждого item после GREEN predecessor |
| P4.16 → P4.18 | Property/source integration, 8 общих Required Reads | Отдельный prepare каждого item; реальные engines P4.18 обязательны |

Эти пары нельзя передавать одной item-list командой: Task требует соседние числовые номера внутри Routing-батча.
Продолжение допустимо при авторизации всей фазы (`task:plan-run 2026.10.01-№1-AZGUARD-V1 P4 --reviewer grok/grok-4.7 --review-effort high`)
или явно разрешённого scope; отдельный запуск первого solo-item не разрешает второй. На каждом item заново проверяются
readiness, route/capabilities, актуальные Inputs/bundle и Validation; необязательный новый процесс между пунктами не нужен.
При недостаточном контексте сохраняется штатный checkpoint, consumer не начинается с устаревшим bundle.
20 канонических запусков вместо 22 оставшихся; три solo-последовательности дополнительно допускают reuse в одном root.
Per-item review при переданном selector сохраняется для каждого implementation item, не один verdict на весь батч.

## Owner gates (summary)

| Where | What does | Blocks |
|---|---|---|
| P8.5 | тег 1.0.0-beta.1, abandoned-пометки | P8.8 |
| P8.6 | задача в репозитории Vaulter | — |
| P8.8 | выпуск 1.0.0 | — |
