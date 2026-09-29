# External design references

## PLAN1 — audit verification 2026-09-22

Perplexity was used to locate primary sources. The links below were also opened
directly over HTTPS on 2026-09-22; conclusions and boundaries are recorded in
`plans/archive/2026.09.22-№1-AZGUARD-CORRECTNESS/findings/verification.md`.

| Source | Verified fact / use |
|:--|:--|
| [Laravel 12 scoped bindings](https://github.com/laravel/docs/blob/12.x/container.md#binding-scoped-singletons) | Scoped resets on request/job lifecycle; not a fiber-local guarantee. PLAN1.P4 |
| [Laravel 12 Eloquent events](https://github.com/laravel/docs/blob/12.x/eloquent.md#events) | Mass update/delete bypass model events. PLAN1.P2 |
| [Laravel 13 transaction manager](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/DatabaseTransactionsManager.php) | after-commit callbacks tie to the root commit but run in-process without documented persistence/retry; external cache is outside the DB transaction. PLAN1.P2 |
| [Filament 5 panel transactions](https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Panel/Concerns/HasDatabaseTransactions.php) | Panel database transactions are off by default. PLAN1.P2 |
| [PSR-16](https://www.php-fig.org/psr/psr-16/) | Portable cache keys are alphabet/length limited; TTL allows early eviction and is not an authorization deadline. PLAN1.P1 |
| [Laravel 13 cache](https://laravel.com/docs/13.x/cache) | TTL/forever eviction does not replace absolute expiry checks; locks have owner/TTL/timeout but do not atomic-commit DB+cache. PLAN1.P1, PLAN1.P2 |
| [Laravel 13 Eloquent](https://github.com/laravel/docs/blob/13.x/eloquent.md) | Model builders use the model table/connection and apply global scopes. PLAN1.P3 |
| [Laravel 13 query builder](https://github.com/laravel/docs/blob/13.x/queries.md#running-database-queries) | `DB::table()` targets an explicit table/connection and is not an Eloquent model builder. PLAN1.P3 |
| [Filament 5 Resource](https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Resources/Resource.php) | Resources resolve models via overridable `static::getModel()`; checked against Filament 5.7.1. PLAN1.P3 |
| [PostgreSQL 16 unique indexes](https://www.postgresql.org/docs/16/indexes-unique.html) | Ordinary unique treats NULLs as distinct; `NULLS NOT DISTINCT` is the exact path. PLAN1.P6 |
| [PostgreSQL 16 expression indexes](https://www.postgresql.org/docs/16/indexes-expressional.html) | Unique expression indexes enforce computed identity with explicit syntax/cost. PLAN1.P6 |
| [MySQL 8.0 CREATE INDEX](https://dev.mysql.com/doc/refman/8.0/en/create-index.html) | Functional key parts use `(expr)`; capability must be checked and is not assumed for MariaDB. PLAN1.P6 |
| [MySQL 8.0 InnoDB limits](https://dev.mysql.com/doc/refman/8.0/en/innodb-limits.html) | 16-KiB pages with DYNAMIC/COMPRESSED allow up to 3,072 indexed bytes; 8-KiB/4-KiB pages reduce limits. Checked 2026-09-23 for D14/P6.2. |
| [MySQL 8.0 utf8mb4](https://dev.mysql.com/doc/refman/8.0/en/charset-unicode-utf8mb4.html) | utf8mb4 uses up to four bytes per character; D14 width budgets are estimates, not substitutes for emitted DDL tests. Checked 2026-09-23. |
| [SQLite CREATE INDEX](https://sqlite.org/lang_createindex.html) | Ordinary unique allows multiple NULLs; expression indexes provide the SQLite path. PLAN1.P6 |
| [MariaDB generated columns](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns) | MariaDB needs its own proof; MySQL functional-index SQL is not portable. PLAN1.P6 |

Upstream branches move: re-check installed sources before changing behaviour on
another version. Extracted material is not vendored and is not executor instructions.

## Architecture audit 2026-09-29

The directly checked external design sources and limitations are recorded in
`audits/2026-09-29-audit/Research/primary-sources.md` in the repository.
Perplexity was used for discovery and synthesis; load-bearing facts were checked
against primary documentation. Key sources: [Laravel 13 authorization](https://laravel.com/docs/13.x/authorization),
[Eloquent events](https://laravel.com/docs/13.x/eloquent#events),
[read/write connections](https://laravel.com/docs/13.x/database#read-and-write-connections),
[Filament 5 Resources](https://filamentphp.com/docs/5.x/resources/overview),
[PHPStan internal symbols](https://phpstan.org/writing-php-code/phpdocs-basics#internal-symbols),
[OWASP Multi Tenant Security](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html),
[Cedar authorization](https://docs.cedarpolicy.com/auth/authorization.html), and
[Composer path repositories](https://getcomposer.org/doc/05-repositories.md#path).
