# Внешние источники проектных решений

## PLAN1 — проверка аудита 2026-09-22

Perplexity использован для поиска первоисточников. Следующие материалы дополнительно
открыты напрямую через HTTPS 2026-09-22; выводы и границы — в
`plans/archive/2026.09.22-№1-AZGUARD-CORRECTNESS/findings/verification.md`.

| Источник | Проверенный факт / применение |
|:--|:--|
| [Laravel 12 scoped bindings](https://github.com/laravel/docs/blob/12.x/container.md#binding-scoped-singletons) | Scoped сбрасывается на request/job lifecycle; это не обещание fiber-local state. PLAN1.P4 |
| [Laravel 12 Eloquent events](https://github.com/laravel/docs/blob/12.x/eloquent.md#events) | Mass update/delete обходят model events. PLAN1.P2 |
| [Laravel 13 transaction manager](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/DatabaseTransactionsManager.php) | after-commit callbacks связаны с root commit, но выполняются тем же процессом без documented persistence/retry; внешний cache не часть DB transaction. PLAN1.P2 |
| [Filament 5 panel transactions](https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Panel/Concerns/HasDatabaseTransactions.php) | Panel database transactions по умолчанию выключены. PLAN1.P2 |
| [PSR-16 Common Interface for Caching Libraries](https://www.php-fig.org/psr/psr-16/) | Portable cache keys ограничены alphabet/length; TTL допускает раннее eviction и не является authorization deadline. PLAN1.P1 |
| [Laravel 13 cache](https://laravel.com/docs/13.x/cache) | Cache backend TTL/forever и eviction не заменяют absolute expiry check; atomic lock имеет owner/TTL и timeout, но не даёт atomic DB+cache commit. PLAN1.P1, PLAN1.P2 |
| [Laravel 13 Eloquent models, scopes and connections](https://github.com/laravel/docs/blob/13.x/eloquent.md) | Model builder использует model table/connection и применяет global scopes. PLAN1.P3 |
| [Laravel 13 query builder](https://github.com/laravel/docs/blob/13.x/queries.md#running-database-queries) | `DB::table()` начинает query с явной table/connection и не является Eloquent model builder. PLAN1.P3 |
| [Filament 5 Resource source](https://github.com/filamentphp/filament/blob/5.x/packages/panels/src/Resources/Resource.php) | Resource разрешает model через overridable `static::getModel()`; сверено с installed Filament 5.7.1. PLAN1.P3 |
| [PostgreSQL 16 unique indexes](https://www.postgresql.org/docs/16/indexes-unique.html) | Ordinary unique treats NULLs as distinct; `NULLS NOT DISTINCT` provides exact PostgreSQL path. PLAN1.P6 |
| [PostgreSQL 16 expression indexes](https://www.postgresql.org/docs/16/indexes-expressional.html) | Unique expression indexes can enforce computed identity; expression syntax and maintenance cost are explicit. PLAN1.P6 |
| [MySQL 8.0 CREATE INDEX](https://dev.mysql.com/doc/refman/8.0/en/create-index.html) | Functional key parts are `(expr)` and are a MySQL-specific capability; version/capability must be checked and not inferred for MariaDB. PLAN1.P6 |
| [MySQL 8.0 InnoDB limits](https://dev.mysql.com/doc/refman/8.0/en/innodb-limits.html) | 16-KiB pages with DYNAMIC/COMPRESSED row format allow at most 3,072 indexed bytes; 8-KiB/4-KiB pages reduce that limit to 1,536/768. Checked directly 2026-09-23 for D14/P6.2. |
| [MySQL 8.0 utf8mb4](https://dev.mysql.com/doc/refman/8.0/en/charset-unicode-utf8mb4.html) | utf8mb4 may use up to four bytes per character; D14's static width is a worst-case estimate, not a substitute for emitted DDL tests. Checked directly 2026-09-23. |
| [SQLite CREATE INDEX](https://sqlite.org/lang_createindex.html) | Ordinary unique permits multiple NULLs; deterministic expression indexes provide the SQLite path. PLAN1.P6 |
| [MariaDB generated columns](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns) | MariaDB needs its own generated-column/index proof; MySQL functional-index SQL is not treated as portable. PLAN1.P6 |

Версионные ветки подвижны: перед изменением поведения на другой версии сверять installed
source. Извлечённые материалы не vendored и не являются инструкциями исполнителю.
