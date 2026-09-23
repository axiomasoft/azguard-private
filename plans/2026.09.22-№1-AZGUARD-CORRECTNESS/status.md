<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-09-23
inputs_sha256: ddec214616ac9f443fb076219a9408de9a8df92124ce3f5ebb9a16b274586305
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | — |
| Status | 🟡 In progress |
| Last Updated | 2026-09-23 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P1 | Идентичность и срок жизни кэша | 2/2 | 🟢 Done |
| P2 | Атомарные мутации и согласованная инвалидация | 0/3 | 🟡 In progress |
| P3 | Согласованная подмена моделей | 0/2 | ⬜ Not started |
| P4 | Runtime panel и единые validation boundaries | 0/2 | ⬜ Not started |
| P5 | Role identity и безопасное создание панелей | 0/2 | ⬜ Not started |
| P6 | Схема, идентификаторы и безопасное обновление | 0/2 | ⬜ Not started |
| P7 | Qualification, документация и подготовка релиза | 0/2 | ⬜ Not started |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Изолировать все cache keys по субъекту | 🟢 Done | 2026-09-23 |
| P1.2 | Учитывать expiration на каждом cache hit | 🟢 Done | 2026-09-23 |

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | Один атомарный role-permission sync | 🟡 In progress | 2026-09-23 |
| P2.2 | Согласовать revision и commit protocol | ⬜ Not started | — |
| P2.3 | Проверить отказ backend и безопасный сброс | ⬜ Not started | — |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Закрыть runtime bypasses config моделей | ⬜ Not started | — |
| P3.2 | Завершить Filament и diagnostics интеграцию | ⬜ Not started | — |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Разделить registry и request/job state | ⬜ Not started | — |
| P4.2 | Согласовать панель, permission и attribute validation | ⬜ Not started | — |

## Phase P5

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P5.1 | Зафиксировать и обеспечить identity code/DB roles | ⬜ Not started | — |
| P5.2 | Сделать scaffold предсказуемым | ⬜ Not started | — |

## Phase P6

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P6.1 | Безопасные migration lifecycle и dedupe | ⬜ Not started | — |
| P6.2 | Проверить SQL identity portability | ⬜ Not started | — |

## Phase P7

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P7.1 | Подтвердить regression и support matrix | ⬜ Not started | — |
| P7.2 | Согласовать документацию и release recipe | ⬜ Not started | — |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-09-22 | GPT-6/unknown | plan-design · no-op · Основной дизайн записан: все фазы остаются скелетами для sol/high; runtime selector/effort автора не аттестован. Дизайн ещё проходит структурную проверку. |
| 2026-09-22 | GPT-6/unknown | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-22 | Codex-root | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-22 | Codex-root | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-22 | Codex-root | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-22 | plan-designer/Codex-root | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-22 | plan-designer/Codex-root | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-22 | plan-designer/gpt-6-sol (route-unmapped) | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-22 | plan-designer/gpt-6-sol (route-unmapped) | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-22 | plan-designer/gpt-6-sol (route-unmapped) | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-23 | plan-designer/Codex-root (route-unmapped) | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-23 | plan-designer/codex-gpt-6-sol | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-23 | plan-designer/codex-gpt-6-sol | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-23 | plan-designer/codex-gpt-6-sol | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-23 | gpt-6-sol/high | plan-audit · audit-red · RED A1/A2; see findings/design-audit-2026-09-23.md. Next: plan-design P2.2 then P6.2; no execution before GREEN. |
| 2026-09-23 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-23 | gpt-6-sol/high | plan-audit · audit-green · Bounded A1/A2/A3 design recheck GREEN; see findings/design-recheck-2026-09-23.md. Product implementation and MySQL DDL remain P1-P7 execution evidence. |
| 2026-09-23 | gpt-6-sol/high | plan-design · no-op · Design finalized; execution has not started. |
| 2026-09-23 | composer-2.5-fast/medium | plan-exec · blocked · SubjectIdentity + azg:v2 cache keys implemented; P1.1 Validation not run — Cursor beforeShellExecution hook security.safe-dirs fail-closed (empty hook output). Pest/Pint/git diff --check and plan-work finalize pending rerun from a shell-capable session. |
| 2026-09-23 | composer-2.5/medium | plan-exec · blocked · Implementation complete (+ DirectGrantGrantableMoveCacheTest, p1.1-validate.sh, execution finding, handoff P1.2). Formal item close pending owner validate script GREEN + plan-work finalize. |
| 2026-09-23 | composer-2.5/medium | plan-exec · closed-green · SubjectIdentity, azg:v2 cache keys, typed invalidation; validate GREEN; Cursor lifecycle hook fix |
| 2026-09-23 | grok-4.6/high | plan-design · no-op · Owner attested manual design finish; current authored fingerprint recorded so execution gate matches the approved sheet. |
| 2026-09-23 | grok-4.6/high | plan-audit · audit-green · Owner attested: manual design GREEN remains; bind existing recheck to current fingerprint after routing-only sheet amendment. No new product audit. |
| 2026-09-23 | grok-4.6/high | plan-exec · in-progress · — |
| 2026-09-23 | grok-4.6/high | plan-exec · closed-green · validUntil, v2 envelope, hit-time expiry; Pest 78, PHPStan clean |
| 2026-09-23 | grok-4.6/high | plan-close · closed-green · P1.1 and P1.2 GREEN; inline phase close |
| 2026-09-23 | grok-4.6/high | plan-exec · in-progress · — |
| 2026-09-23 | grok-4.6/high | plan-design · no-op · Owner attested: P1.md contract-first block added so phase closure matches the generator; design GREEN remains. |
| 2026-09-23 | grok-4.6/high | plan-audit · audit-green · Owner attested: bind GREEN design to fingerprint after P1 contract-first block for closure generator. No new product audit. |

## Owner Gates

- —
