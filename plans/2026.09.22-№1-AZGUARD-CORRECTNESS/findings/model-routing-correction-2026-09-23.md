# Исправление маршрутов исполнения — 2026-09-23

По прямому указанию владельца исключён `gpt-5.6-terra` из плановых запусков.
Ресурсно-ориентированный маршрут: bounded P1.1/B3/B5/B7 →
`implementation/medium` (Cursor Composer 2.5); security/concurrency/schema
P1.2/B2/B4/B6 → `frontier/high` (Cursor Grok 4.6 либо 4.7). GPT‑5.6 Sol —
только резерв при недоступности первичного маршрута; GPT‑6 Sol — для отдельно
допущенных аудитов. Нормативный план хранит классы/effort, не provider selectors;
конкретные модели здесь — операторское доказательство, а не новый Routing.

Локальные `agent --list-models` и `agent --help` подтверждают CLI selectors
`composer-2.5`, `cursor-grok-4.6-high`, `grok-4.7-high`, флаги `--workspace`,
`--model`, `--force`, `--sandbox disabled`, `--trust`, `--approve-mcps`.
`codex --help` подтверждает `-C`, `-m`, `-c`, `-a`, `-s` для резервного
GPT‑5.6 Sol и аудиторского GPT‑6 Sol.

**Внешний defect исполнения:** установленный Task Cursor route adapter при
явном `agent --model` фиксирует effort=`medium` и распознаёт только старые
семантические IDs `grok-4.6`/`grok-4.7`, а текущий CLI выдаёт
`cursor-grok-4.6-high`/`grok-4.7-high`. Поэтому pinned CLI запуск Grok
может не пройти Task route admission до исправления адаптера в owning
swissknifeman package. AzGuard план не меняет чужой репозиторий; это не
представлено как GREEN Grok CLI evidence. Текущий P1.1 на Composer 2.5
допускается как `implementation/medium`.
