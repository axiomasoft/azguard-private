---
id: D2
date: 2026-10-01
status: accepted
item: P0.5
items: [P0.4, P0.5, P6.9]
supersedes: []
superseded_by: null
---
# D2 — Новый код в packages/core и packages/filament; 0.3 заморожен в legacy/0.3

**Actor:** plan-designer / Claude Opus 5.5 (frontier)
**Evidence:** RAG:— `packages/core/src` (151 PHP-файл, namespace `AzGuard\`), `packages/context` (`AzGuard\Context\`), `packages/filament` (`AzGuard\Filament\`); целевые имена `Panels\Panel`, `Panels\PanelProvider`, `Panels\PanelResolver`, `Roles\BaseRole` совпадают с существующими 0.3-классами; `04-packages-and-layout.md` §3 задаёт целевую раскладку в `packages/core/src`.

## Solution

P0.5 одним изменением переносит код 0.3 (`packages/core|context|filament` и `tests/`) через `git mv`
в `legacy/0.3/`, исключает его из автозагрузки, Pest, PHPStan, Pint, Rector и CI, и создаёт пустые
пакеты 1.0: `packages/core` (`axiomasoft/azguard`, `AzGuard\`) и `packages/filament`
(`axiomasoft/azguard-filament`, `AzGuard\Filament\`) с зелёными пустыми гейтами. Пакет `context`
не воссоздаётся (вливается в ядро, D03 досье). `legacy/0.3/` — только справочник для
исполнителей; P6.9 удаляет его целиком и проверяет отсутствие старых имён arch-тестом.

## Why

Старый и новый код в одном namespace `AzGuard\` не могут сосуществовать в автозагрузке: имена
целевых классов совпадают со старыми. Варианты:
- патчить/переименовывать 0.3 поэтапно — противоречит D01 досье (без совместимости, с нуля);
- удалить 0.3 сразу и читать через `git show v0.3.0:` — тег не содержит исправлений PLAN1 после
  0.3.0, а исполнителям неудобно искать по истории;
- отдельный namespace для 1.0 и переименование в конце — лишняя массовая правка в P6.

Заморозка в `legacy/0.3/` даёт чистый namespace сразу, сохраняет справочник (discovery, генераторы,
Filament-ресурсы) и одну точку удаления.

## Consequences

С P0 по P6.9 в репозитории нет рабочего 0.3: допустимо, пакет не в эксплуатации (D01 досье).
Grep/анализ исполнителей исключают `legacy/`. P6.9 удаляет `legacy/` и старые таблицы/конфиг/доки.
Документация `docs/` переписывается в P8.2; до этого она описывает 0.3 и помечается как устаревшая в P0.5.
