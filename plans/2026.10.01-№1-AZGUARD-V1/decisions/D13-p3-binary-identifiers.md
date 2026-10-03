---
id: D13
date: 2026-10-04
status: accepted
item: P3.2
items: [P3.2, P3.3, P4.4, P8.4]
supersedes: []
superseded_by: null
---
# D13 — Точное сравнение ID на MySQL/MariaDB

**Actor:** owner / Codex gpt-6.1-sol.
**Evidence:** brief/P3-binary-identifiers-owner-message.md; findings/P3-execution.md, MySQL 8.4.10 probe.

## Solution

Уточняет D12 п.6: MySQL/MariaDB ID(n) и строковые host keys создаются VARBINARY(n) вместо VARCHAR ascii_bin.
ULID — VARBINARY(26), UUID при строковом хранении — VARBINARY(36). PG ID(n) — VARCHAR(n) COLLATE C, SQLite — BINARY.
Кодек проверяет канон ASCII; DDL и unique сравнивают байты, включая хвостовые пробелы, точно.
Остальная схема, размеры колонок, индексы и CHECK остаются как в D12 и 08 §2.

## Why

MySQL ascii_bin имеет PAD SPACE: `a` и `a ` равны. VARBINARY обеспечивает заявленный побайтовый контракт и бюджет индекса.

## Consequences

DDL-снимки отражают binary types на MySQL/MariaDB. PDO возвращает строки; идентичность и канон не меняются.
