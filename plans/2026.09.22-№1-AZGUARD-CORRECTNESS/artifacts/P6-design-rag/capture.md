# Perplexity capture — P6 — 2026-09-23

**Tool:** `perplexity.search` (ordinary search)
**Capture kind:** response text returned to the design session
**Normative status:** non-normative retrieval lead; load-bearing claims require direct primary-source or repository verification.

## Query

Use only primary sources and provide a concise evidence ledger with URLs, no quotations. Compare PostgreSQL 16 NULLS NOT DISTINCT, SQLite unique/index expression NULL behavior, MySQL 8.0 functional key parts and version limits, and MariaDB generated-column/index differences. Focus on enforcing exact null-safe composite uniqueness for integer, UUID/ULID and string identities without sentinel collisions. Explicitly mark engine-specific and unresolved portability limits.

## Response

PostgreSQL 16 is the only engine in this set with a native, type-preserving declaration for null-safe composite uniqueness: UNIQUE NULLS NOT DISTINCT. SQLite, MySQL 8.0, and MariaDB require derived key parts; without a database-specific domain guarantee, a single COALESCE(..., sentinel) key is not collision-free for nullable integer, UUID/ULID, or arbitrary string identities.

## Target invariant

For a composite identity such as (scope_id, external_id), “exact null-safe uniqueness” means two rows conflict precisely when each corresponding component is equal or both are NULL:
Thus (7, NULL) may appear once, (NULL, NULL) may appear once, and (7, 'x') may appear once—while (7, NULL) and (NULL, 'x') remain different keys.

## Comparison

All four engines’ ordinary UNIQUE semantics permit multiple NULL values, so an ordinary composite unique key does not enforce the target invariant. PostgreSQL changes that rule only when NULLS NOT DISTINCT is specified; SQLite, MySQL, and MariaDB retain NULL-distinct uniqueness.

## Engine DDL patterns


## PostgreSQL 16+

Use the native constraint form whenever PostgreSQL 16+ is your deployment baseline:
```
ALTER TABLE identities ADD CONSTRAINT identities_scope_external_uq UNIQUE NULLS NOT DISTINCT (scope_id, external_id);
```
Or:
```
CREATE UNIQUE INDEX identities_scope_external_uq ON identities (scope_id, external_id) NULLS NOT DISTINCT;
```
This is type-preserving: no casts, sentinels, flag columns, or expression-index matching concerns. In a multicolumn index, PostgreSQL rejects a duplicate only if all indexed positions compare equal under the selected NULL rule.
Portability note: NULLS NOT DISTINCT is PostgreSQL-specific syntax and is not a portable SQL migration primitive.

## SQLite

SQLite unique indexes treat every NULL as distinct. SQLite does support expression indexes, so encode nullness separately for each nullable key component:
```
CREATE UNIQUE INDEX identities_scope_external_uq ON identities ( scope_id IS NULL, scope_id, external_id IS NULL, external_id );
```
This is collision-free because NULL does not have to impersonate any domain value:
However, this pattern has a critical flaw as written: SQLite still considers NULL unequal inside the value component. Two rows with scope_id = NULL both generate (1, NULL), and SQLite treats those NULLs as different. Therefore, the boolean flag alone is insufficient in SQLite.
For SQLite, use partial unique indexes for each nullability pattern. For a two-column nullable key, that is four indexes:
```
CREATE UNIQUE INDEX identities_uq_nn ON identities (scope_id, external_id) WHERE scope_id IS NOT NULL AND external_id IS NOT NULL; CREATE UNIQUE INDEX identities_uq_n0 ON identities (scope_id) WHERE scope_id IS NOT NULL AND external_id IS NULL; CREATE UNIQUE INDEX identities_uq_0n ON identities (external_id) WHERE scope_id IS NULL AND external_id IS NOT NULL; CREATE UNIQUE INDEX identities_uq_00 ON identities ((1)) WHERE scope_id IS NULL AND external_id IS NULL;
```
This is exact and sentinel-free. SQLite partial indexes include only rows whose predicate is true; a UNIQUE partial index enforces uniqueness among that included subset. SQLite supports expression indexes from 3.9.0, but expressions cannot use other tables, subqueries, or non-deterministic functions.
SQLite scalability limit: exact enforcement costs 2n2^n2n partial unique indexes for nnn nullable identity components. That is practical for one or two nullable parts, becomes awkward at three, and is a strong reason to normalize the model or choose PostgreSQL when many nullable dimensions are part of identity.

## MySQL 8.0

MySQL 8.0 ordinary unique indexes permit multiple NULLs. Functional key parts are available from 8.0.13 and may participate in UNIQUE indexes, including mixed regular and functional parts.
A superficially tempting design is:
```
CREATE UNIQUE INDEX identities_uq ON identities ( scope_id, (COALESCE(external_id, '__NULL__')) );
```
Do not use that for exact identity semantics unless __NULL__ is structurally impossible as a non-null value. It aliases NULL with the real string '__NULL__'.
A collision-free MySQL approach uses generated columns plus a presence flag, but it must avoid leaving a nullable generated value inside the final unique key. For fixed-width binary identities, a proper no-domain-sentinel representation can use a binary payload whose value is arbitrary only when a separate flag says “present”; the payload must nevertheless be non-null. In practice, that means assigning a fallback byte sequence, which is still a sentinel at the stored representation level—even though the flag makes it semantically unambiguous. If the requirement prohibits any sentinel value whatsoever, MySQL has no single-index, type-preserving equivalent to PostgreSQL’s feature.
A robust practical schema therefore makes the null-state explicit and chooses a fallback that is excluded by a database-enforced domain rule:
```
CREATE TABLE identities ( scope_id BIGINT NULL, external_id VARCHAR(255) NULL, external_id_is_null TINYINT AS (external_id IS NULL) STORED, external_id_key VARCHAR(255) AS (COALESCE(external_id, '')) STORED, CONSTRAINT identities_external_id_key_domain CHECK (external_id IS NULL OR external_id <> ''), UNIQUE KEY identities_scope_external_uq ( scope_id, external_id_is_null, external_id_key ) );
```
This becomes exact only under the explicit invariant that a real identity can never be ''. That condition must be enforced by CHECK and application validation, and migration/import paths must preserve it. For arbitrary strings where every string—including empty string—is valid, use a different data model or separate tables; there is no universal sentinel-free, one-index encoding in MySQL’s NULL-distinct unique-index semantics.
For INTEGER, use a fallback only if the value is excluded from the valid domain, such as an unsigned identity stored in a signed type with -1 blocked by CHECK. For UUID/ULID:
- Prefer BINARY(16) UUID values and reserve a byte sequence only if a CHECK excludes it.
- For ULIDs stored as CHAR(26), reserve a non-ULID string only if canonical ULID validation is enforced.
- Do not use a prefix unique index: it enforces uniqueness only over the prefix, not over the complete identity.
MySQL version and DDL limits
- Functional key parts require MySQL 8.0.13+.
- Functional key parts are hidden virtual generated columns; they inherit generated-column expression restrictions. Subqueries, parameters, variables, stored functions, and loadable functions are disallowed.
- A functional key part cannot be a bare column name, cannot use a column prefix, and cannot participate in a primary key or foreign-key specification.
- UNIQUE is allowed on indexes that include functional key parts, while primary keys are not.
- For explicit generated columns, InnoDB supports secondary indexes on virtual generated columns, and such indexes may be unique.

## MariaDB

MariaDB supports both VIRTUAL and PERSISTENT/STORED generated columns, and it permits indexes on either. It does not allow a generated column as the table primary key.
Use explicit generated columns rather than assuming MySQL 8 functional-index syntax and behavior:
```
CREATE TABLE identities ( scope_id BIGINT NULL, external_id VARCHAR(255) NULL, external_id_is_null TINYINT AS (external_id IS NULL) PERSISTENT, external_id_key VARCHAR(255) AS (COALESCE(external_id, '')) PERSISTENT, CONSTRAINT identities_external_id_domain_chk CHECK (external_id IS NULL OR external_id <> ''), UNIQUE KEY identities_scope_external_uq ( scope_id, external_id_is_null, external_id_key ) ) ENGINE=InnoDB;
```
This has the same semantic condition as the MySQL version: it is exact only because '' is excluded as a real non-null identity. Without a truly excluded fallback, it is not collision-free.
MariaDB’s generated-column differences relevant here:
- Both VIRTUAL and PERSISTENT generated columns may be indexed.
- Generated columns cannot be a primary key; persistent generated columns can participate in foreign keys, subject to documented referential-action restrictions.
- Indexed or persistent generated expressions must be consistent across SQL modes. MariaDB explicitly identifies SQL-mode-dependent behavior, including character padding and unsigned subtraction, as a consistency risk.
- MariaDB documents virtual columns as a mechanism for conditional/partial-style uniqueness, but this does not create PostgreSQL-like NULL-equals-NULL behavior by itself.
- MariaDB optimizer recognition of indexed virtual-column expressions is version-sensitive: current documentation distinguishes behavior before 11.8.

## Identity-type guidance

For strings, “exact” also depends on collation. A unique index enforces equality under its column/index collation, not necessarily byte identity. If identities must be byte- or case-sensitive, define that deliberately—e.g., a binary collation/type—rather than relying on server defaults. MySQL’s own functional-index documentation notes that collation/type mismatches can prevent expression matching for query optimization; that is separate from constraint enforcement but matters operationally.

## Portability limits


## Explicitly engine-specific

- UNIQUE NULLS NOT DISTINCT: PostgreSQL-specific and the best option for the stated invariant.
- SQLite’s exact, sentinel-free fallback: a matrix of partial unique indexes. SQLite’s NULL-distinct unique semantics make a single ordinary/unique expression index insufficient when indexed expressions still yield NULL.
- MySQL functional key parts: MySQL 8.0.13+ specific and implemented as hidden virtual generated columns.
- MariaDB’s VIRTUAL / PERSISTENT generated-column behavior, DDL limits, and optimizer behavior are not interchangeable with MySQL despite syntax overlap.

## Unresolved or non-portable by design

- There is no one-DDL-statement, exact, sentinel-free portable solution across PostgreSQL, SQLite, MySQL, and MariaDB for arbitrary nullable composite identity values.
- There is no universal collision-free COALESCE sentinel for an unrestricted integer, UUID/ULID, or string domain. A value is safe only when excluded by an enforceable domain constraint.
- A nullness flag plus the original nullable value does not solve SQLite uniqueness: SQLite still treats NULL values in the value key part as distinct.
- For nnn nullable columns, SQLite’s exact partial-index solution grows to 2n2^n2n indexes.
- MySQL/MariaDB generated-column designs are operationally sensitive to expression determinism, declared result types, collations, SQL modes, supported storage engines, and deployment version.

## Recommendation

- PostgreSQL 16+: use UNIQUE NULLS NOT DISTINCT; it is exact, concise, and no-sentinel for all identity types.
- SQLite: use the partial-unique-index matrix for up to two nullable identity dimensions. Do not rely on COALESCE unless the fallback is formally excluded.
- MySQL 8.0 / MariaDB: if a fallback identity value is demonstrably outside the valid domain and enforced by CHECK plus canonical validation, use a generated/functional projection with an explicit null-state flag. Otherwise, do not claim exact null-safe uniqueness from a single unique key.
- Cross-engine product schema: if strict portability matters more than a nullable composite key shape, model “no external identity” separately—e.g., split nullable identity states into a distinct table or make identity presence explicit with a non-null discriminator. This makes the uniqueness key entirely non-null and avoids sentinel semantics.

Sources:
1. [postgresql](https://www.postgresql.org/docs/16/indexes-unique.html)
2. [dev.mysql](https://dev.mysql.com/doc/refman/8.0/en/create-index.html)
3. [mariadb](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns)
4. [mariadb](https://mariadb.com/docs/server/mariadb-quickstart-guides/mariadb-indexes-guide)

