---
name: cursor-pagination-design
bucket: php
version: 0.1.0
description: "Stable keyset (seek) pagination for infinite scroll/feeds: sortable non-nullable key, composite index, handle ties/concurrent inserts, avoid offset drift. Activate building infinite-scroll, cursor/seek pagination, feed lists, or fixing 'duplicates/skips while scrolling'. Triggers: keyset, seek pagination, paginate after, offset drift."
risk: write
persona: oss-dev
tags: ["php", "laravel", "pagination", "cursor", "database"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

# Cursor (keyset / seek) Pagination

Stable pagination for feeds / infinite-scroll, where rows are inserted while users scroll.

## Rules

1. **Key = a sortable, NON-nullable column** — preferably a sortable PK (uuid7 / ulid / snowflake) or a `(sort_col, id)` composite. A `NULL`-able order key (e.g. `last_message_at`) is a RED FLAG: `NULL` is not comparable, so the cursor skips/duplicates rows. Fix: a synthetic non-null key, `COALESCE` to a sentinel + tiebreak, or fall back to offset.
2. **Composite tiebreak.** Order by `(sort_col, id)` and seek with `WHERE (sort_col, id) < (?, ?)` so equal `sort_col` values don't drop or repeat rows.
3. **Composite index** on the exact `(sort_col, id)` order — otherwise the seek scans.
4. **Offset is unstable** under concurrent inserts (rows shift) — don't use `OFFSET` for live feeds; keyset is insert-stable.
5. **Opaque cursor.** Encode `(sort_col, id)` into the cursor token; don't leak raw ids/timestamps the client can forge.

## Red flag (real incident)

- `orderBy('last_message_at')->cursorPaginate()` where `last_message_at` is nullable — a test that stamps `now()` on every row hides the NULL-ordering bug; production rows with NULL break the cursor.

## Related

- `php:database` (indexes), `php:production-readiness-checklist` (cursor invariant).

<!-- ru-source-sha256: a7c534f097b72338a266c652b96404a3f5f742ab159031ab91d93ea3cc71a31e -->
