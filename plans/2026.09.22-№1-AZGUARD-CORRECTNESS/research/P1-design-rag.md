# Внешние подтверждения P1 — cache identity и expiry

Сырой retrieval capture: `../artifacts/P1-design-rag/capture.md`. Он не нормативен:
Perplexity использован для поиска и синтеза, а несущие выводы сверены с PSR-16,
Laravel Cache и текущим кодом. Точные ссылки также собраны в `../../../docs/reference.md`.

## Проверенные выводы

| Факт | Статус | Применение |
|:--|:--|:--|
| PSR-16 ограничивает переносимый ключ 64 символами и запрещает `{ } ( ) / \\ @ :` | primary-source verified | P1.1 использует короткий fixed-length digest с безопасным alphabet |
| TTL — срок хранения cache item; реализация может удалить его раньше | primary-source verified | cache hit не является доказательством действия grant |
| Laravel предоставляет TTL/forever/locks, но не задаёт authorization deadline | primary-source verified | P1.2 хранит абсолютный `validUntil` в payload и проверяет его на каждом hit |
| Morph type + canonical persisted ID — правильная identity именно для AzGuard | repo-grounded inference | это не стандарт PSR/Laravel; основание — F1 и polymorphic persistence package |
| Version/revision dimension нужен для немедленного revoke | repo-grounded design | P1 даёт subject identity; authoritative revision добавляет P2 |

## Коррекция retrieval-ответа

Ответ предложил обрезанный SHA-256 как один из вариантов. План не принимает конкретную
длину вслепую: P1.1 обязан доказать размер ключа с полным prefix/kind/panel/discriminator
и оценить collision boundary. Payload может хранить полный digest/material для defensive
miss. Конкретная encoding остаётся internal и покрывается collision/length tests.

## Нестандартизованные части

Ни PSR-16, ни Laravel не определяют morph aliases, canonicalization UUID/ULID,
tenant/panel/context dimensions, cache-envelope versioning, clock authority или policy
stale-allow. Эти решения нормативно принадлежат `D3` и `research/P1-design.md`, а не
внешнему источнику.

