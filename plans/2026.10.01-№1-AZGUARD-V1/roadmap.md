# Execution Roadmap - 2026.10.01-№1-AZGUARD-V1

<!-- execution-sheet/v1 -->

**Updated:** 2026-10-04 · **Corresponds to plan.md:** верхний слой + детализация P0, P1, P2, P3, P4

## Grouping rule

- Фаза детализируется и исполняется целиком перед следующей (D3); строки добавляются по мере детализации.
- Batch не пересекает фазу; launch использует максимум class/effort/review своих пунктов.
- Owner-gated пункты P8.5/P8.6/P8.8 — всегда `solo`.
- Batch — сплошной диапазон номеров фазы, исполняется в порядке номеров; зависимости пунктов P2 выстроены под номера досье (D8 п.1).

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
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.2` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.3` | `frontier/high` | `none` |
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
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.10` | `implementation/medium` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P4.11` | `frontier/high` | `none` |
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
| P4.2 | solo | plan-run | frontier/high | — | Выдачи из папки: BaseRole, GrantedAutomatically, GrantedToAll, FormerKeys (D15) |
| P4.3 | solo | plan-run | frontier/high | — | Mode-aware policies, Decides, PolicyFor, GateSource::map (D15) |
| P4.4 | solo | plan-run | frontier/high | — | DatabaseSource: назначения, сроки, dynamicPermissions, писатель (D15) |
| P4.17 | solo | plan-run | frontier/high | — | Dynamic Prepare и единый authority fence (D15) |
| P4.5 | solo | plan-run | frontier/high | — | RelationSource и видимость через связи (D15) |
| P4.6 | solo | plan-run | frontier/high | — | Tenant, AssignmentScope и ресурс в проверке (D15) |
| P4.19 | solo | plan-run | frontier/high | — | Context eligibility: native filters и external adapters (D15) |
| P4.7 | solo | plan-run | implementation/medium | — | Суперадмин — признак роли (D15) |
| P4.16 | solo | plan-run | frontier/high | — | Генеративная квалификация движка и scope (D15) |
| P4.18 | solo | plan-run | frontier/high | — | Scoped source integration и регрессия P08 (D15) |
| P4.15 | solo | plan-run | implementation/high | — | CRM-фикстура и R-кейсы границы решения P4.1–P4.7 (D15) |
| P4.13 | solo | plan-run | frontier/high | — | Срез-review P4.1–P4.7: независимая read-only проверка границы решения (D15) |
| P4.8 | solo | plan-run | frontier/high | — | Кэш наборов прав, StateToken, version fence, expiry (D15) |
| P4.20 | solo | plan-run | frontier/high | — | Cache transactions, incarnation и touch (D15) |
| P4.21 | solo | plan-run | frontier/high | — | Квалификация кэша: гонки, Redis, replica lag, latency (D15) |
| P4.9 | solo | plan-run | frontier/high | — | decideMany (D15) |
| P4.10 | solo | plan-run | implementation/medium | — | explain и azguard:explain (D15) |
| P4.11 | solo | plan-run | frontier/high | — | GateBridge (D15) |
| P4.22 | solo | plan-run | frontier/high | — | Exact predicate SPI, partitions и SQL compiler (D15) |
| P4.12 | solo | plan-run | frontier/high | — | Visibility и exact query adapters (D15) |
| P4.23 | solo | plan-run | frontier/high | — | Exact visibility: CRM, parity и EXPLAIN (D15) |
| P4.14 | solo | plan-run | frontier/high | — | Review P4: независимая read-only проверка фазы (D15) |

## Owner gates (summary)

| Where | What does | Blocks |
|---|---|---|
| P8.5 | тег 1.0.0-beta.1, abandoned-пометки | P8.8 |
| P8.6 | задача в репозитории Vaulter | — |
| P8.8 | выпуск 1.0.0 | — |
