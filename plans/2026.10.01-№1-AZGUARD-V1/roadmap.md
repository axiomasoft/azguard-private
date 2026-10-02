# Execution Roadmap - 2026.10.01-№1-AZGUARD-V1

<!-- execution-sheet/v1 -->

**Updated:** 2026-10-02 · **Corresponds to plan.md:** верхний слой + детализация P0, P1

## Grouping rule

- Фаза детализируется и исполняется целиком перед следующей (D3); строки добавляются по мере детализации.
- Batch не пересекает фазу; launch использует максимум class/effort/review своих пунктов.
- Owner-gated пункты P8.5/P8.6/P8.8 — всегда `solo`.

## Execution sheet - the only source of launch commands

| Command | Unified route | Review |
|:--|:--|:--|
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P0.1 P0.2 P0.3 P0.4 P0.5` | `implementation/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P1.1 P1.2 P1.3 P1.4` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P1.6` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P1.7` | `frontier/high` | `none` |
| `task:plan-run 2026.10.01-№1-AZGUARD-V1 P1.8` | `frontier/high` | `none` |

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

## Owner gates (summary)

| Where | What does | Blocks |
|---|---|---|
| P8.5 | тег 1.0.0-beta.1, abandoned-пометки | P8.8 |
| P8.6 | задача в репозитории Vaulter | — |
| P8.8 | выпуск 1.0.0 | — |
