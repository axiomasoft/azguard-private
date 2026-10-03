# Execution Roadmap - 2026.10.01-№1-AZGUARD-V1

<!-- execution-sheet/v1 -->

**Updated:** 2026-10-03 · **Corresponds to plan.md:** верхний слой + детализация P0, P1, P2, P3

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

## Owner gates (summary)

| Where | What does | Blocks |
|---|---|---|
| P8.5 | тег 1.0.0-beta.1, abandoned-пометки | P8.8 |
| P8.6 | задача в репозитории Vaulter | — |
| P8.8 | выпуск 1.0.0 | — |
