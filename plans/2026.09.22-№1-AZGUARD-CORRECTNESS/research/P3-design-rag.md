# Внешние подтверждения P3 — configurable Eloquent models

Сырой capture: `../artifacts/P3-design-rag/capture.md`. Несущие утверждения сверены
с Laravel 13 Eloquent/query docs и Filament 5 `Resource` source; ссылки —
`../../../docs/reference.md`.

## Evidence ledger

- Query, начатый из configured Eloquent class, использует model table/connection и применяет
  его global scopes; hydration возвращает configured subclass.
- `DB::table()` — table-level query builder: он не эквивалентен model builder и не переносит
  casts, hydration, model events/scopes/relations.
- Mass Eloquent updates остаются отдельным исключением: instance events не возникают автоматически.
- Filament Resource разрешает model через overridable `getModel()` и строит resource query от него.

## Граница вывода

Документация не обещает arbitrary ORM, cross-database relations или применение Role scopes к
raw pivot JOIN. D6 ограничивает seam подклассами четырёх AzGuard base models и одной effective
authorization connection.

