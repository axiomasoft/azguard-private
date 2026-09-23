# Внешние подтверждения P6 — null-safe identity and migrations

Сырой capture: `../artifacts/P6-design-rag/capture.md`. В design-сессии 2026-09-22 direct
web adapter failed with `connection failed`; PostgreSQL/SQLite primary pages were then read
through direct HTTPS. MySQL direct host DNS failed тогда, поэтому исходная запись ссылалась
на primary-source record в `../../../docs/reference.md` и capture lead; это не было новым
GREEN external check на ту дату.

2026-09-23 correction for D14: the official MySQL 8.0 InnoDB limits, utf8mb4 and CREATE INDEX
pages were opened directly (links in `../../../docs/reference.md`). They confirm 3,072 indexed
bytes at 16-KiB pages with DYNAMIC/COMPRESSED row format, 1,536/768 at 8-/4-KiB pages, up to
four utf8mb4 bytes/character and MySQL-specific functional key parts. This verifies the
premises of the key-size estimate, **not** successful emitted DDL or a deployed upgrade.

## Verified facts

- PostgreSQL 16 unique indexes treat nulls distinct by default; `NULLS NOT DISTINCT` treats them
  equal, and multicolumn rejection occurs when all indexed positions compare equal.
- SQLite unique indexes treat all NULLs as distinct. Unique partial indexes apply only to rows
  whose predicate is true and can encode exact nullability cases.
- MySQL 8 ordinary unique keys permit repeated NULL; functional key parts are version/capability
  specific (recorded primary source: 8.0.13+).
- MariaDB generated-column/index support is a separate capability surface; MySQL functional-index
  syntax must not be assumed portable.

## Correction of Perplexity synthesis

The response claimed marker+fallback still needs the fallback excluded from the data domain.
That is false when the marker participates in the same unique key: `(is_null=1, fallback)` for NULL
cannot collide with `(is_null=0, real fallback)`. The exact encoding must include, for **every**
nullable component, both the null marker and a non-null full-width normalized value. Leaving the
value NULL or omitting the marker is unsafe. Prefix truncation remains forbidden.

SQLite may use either complete marker+non-null normalized expression parts or exhaustive partial
indexes. The implementation chooses the smallest form proven by actual engine tests and records
the emitted DDL; external synthesis is not the specification.
