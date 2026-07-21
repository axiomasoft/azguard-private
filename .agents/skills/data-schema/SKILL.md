---
name: data-schema
bucket: architect
version: 0.1.0
description: Data schema, entities, attributes, relations. DB-agnostic — markdown tables only
risk: draft
persona: architect
tags: [data, architecture, schema, entities, erd]
requires: [brd]
produces_for: [api-design]
outputs: ["docs/03_Dev/Database_Schema.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: Data Schema Design

Apply when: task is designing data schema, entities, attributes, relations.

## Principle

- DB-agnostic. No SQL, no CREATE TABLE, no engines. Only entities, attributes, types, relations — in markdown tables.
- Place in `docs/03_Dev/Database_Schema.md` or `docs/03_Dev/Database_*.md`.

## Entity format

```
### `table_name` (Human-readable name)

Short description: what this entity stores and why.

| Field | Type | Description |
|:---|:---|:---|
| `id` | uuid / bigint | Primary key |
| `field_name` | string | Field purpose |
| `foreign_id` | uuid | → [[#linked_table]] Relation to entity X |
| `created_at` | timestamp | Creation date |
```

## Relations format

**1. Inline in table** — for FK fields:
```
| `user_id` | uuid | → [[#users]] Record owner |
```

**2. Separate section** at end of file — full picture:
```markdown
## Связи между сущностями

| От | Тип | К | Описание |
|:---|:---|:---|:---|
| `orders` | many-to-one | `users` | Заказ принадлежит одному пользователю |
| `orders` | one-to-many | `order_items` | Заказ содержит несколько позиций |
| `products` | many-to-many | `tags` | Через `product_tags` |
```

Relation types: `one-to-one`, `one-to-many`, `many-to-one`, `many-to-many`

## Data types (agnostic)

| Token | Meaning |
|:---|:---|
| `uuid` | Unique identifier |
| `bigint` | Large integer |
| `string` | String (specify length if critical: `string(255)`) |
| `text` | Long text |
| `boolean` | true/false flag |
| `decimal` | Floating-point number (money, coordinates) |
| `timestamp` | Date and time |
| `date` | Date only |
| `json` | Arbitrary structure |
| `enum` | Enumeration — specify values explicitly |

## Database_Schema.md file structure

```markdown
# Схема данных: ProjectName

## Сущности

### `entity_one` (Название)
...

### `entity_two` (Название)
...

## Связи между сущностями
...

## Индексы и производительность
(только логические, не SQL: "поле X должно быть проиндексировано для поиска по Y")
```

## Add proactively

- `created_at`, `updated_at` — if not explicitly given, add everywhere.
- `deleted_at` (soft delete) — propose if entity is critical and deletion undesirable.
- Versioning (`version` / `revision`) — propose for documents and financial records.
- Name m2m tables explicitly (don't guess from context).

## Hard prohibitions

- No SQL (CREATE TABLE, ALTER, INDEX, CONSTRAINT).
- No specific DBMS mentions (PostgreSQL, MySQL, SQLite).
- No ORM patterns (migrations, models, repositories).
- No storage engines (InnoDB, TimescaleDB hypertables — those go in Architecture.md).

## Related skills

- `pm/brd` — source of entities and requirements (precondition).
- `architect/api-design` — API built on top of this schema (output of this skill).
- `architect/architecture` — DBMS and storage engine choice lives there, not here.
- `php/database` — schema implementation in Laravel migrations/models (boundary: design vs implementation).
- `php/repositories` — data access layer over implemented schema.

<!-- ru-source-sha256: 2b7f7d894ecfe970bdb5fb73b4c7b0a78418b4ab40be309f01b9e3f2721024fe -->
