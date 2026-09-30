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

## Opus design hardening — CRM, 2026-09-30

Three Perplexity queries explored scoped tenant/project RBAC, panel layout/builder naming,
and object-first versus parallel type-first roots,
with the problem described explicitly rather than assuming knowledge of AzGuard.
Primary documentation was opened directly. Findings, project choices and proof boundaries:
[design review](../audits/2026-09-29-audit/opus/evidence/design-review.md).

Sources: [OpenFGA organization context](https://openfga.dev/docs/modeling/organization-context-authorization),
[OpenFGA search with permissions](https://openfga.dev/docs/interacting/search-with-permissions),
[PostgreSQL 13 isolation](https://www.postgresql.org/docs/13/transaction-iso.html),
[SpiceDB consistency](https://authzed.com/docs/spicedb/concepts/consistency),
[Laravel 13 authorization](https://laravel.com/docs/13.x/authorization),
[Laravel 13 Sanctum](https://laravel.com/docs/13.x/sanctum), and
[Filament 5 resources](https://filamentphp.com/docs/5.x/resources/overview).
The initial Resources layout was replaced by the owner-approved Permissions/<Group> with parallel
Policies/Queries/Abilities (D72). That layout and for(...) are project decisions, not an API prescribed
by those sources.


## Opus configured contexts and real CRM acceptance — 2026-09-30

Two additional Perplexity queries explicitly described global/per-role Eloquent context predicates,
subject/actor/actual role inputs, assignment/revoke phases, plugin options/DI/lifecycle/cache and panel isolation.
Primary verification and limits: [flexibility review](../audits/2026-09-29-audit/opus/evidence/flexibility-review.md).
Sources: [Laravel 13 Container](https://laravel.com/docs/13.x/container),
[Eloquent scopes](https://laravel.com/docs/13.x/eloquent),
[Query Builder logical grouping](https://laravel.com/docs/13.x/queries#logical-grouping),
[Octane lifecycle](https://laravel.com/docs/13.x/octane).
The native Model::guard(array) collision was verified directly in local framework v13.33.0 source;
a bounded PHP probe validates the proposed compatible overload. Naming and eligibility composition are
project decisions. The 60 CRM acceptance cases are future runtime qualification requirements.


## Opus OOP/authority redesign and process feasibility — 2026-09-30

Three Perplexity requests covered typed factories/config vs inherited signatures, the full architecture and lifecycle,
and concrete OSS comparison. Full structure included panel layout, code-only roles/DB assignments, two permission
modes, contexts/query limits, plugin DI, storage/locks/tokens, host ownership, editors/workers/import and test gates.
Leads were verified directly; incomplete synthesizer sections were not treated as evidence.
[OOP review and limits](../audits/2026-09-29-audit/opus/evidence/oop-review.md).

Primary sources: [PHP variance](https://www.php.net/manual/en/language.oop5.variance.php),
[named arguments](https://www.php.net/manual/en/functions.arguments.php),
[Filament 5 Plugin interface](https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/panels/src/Contracts/Plugin.php),
[Filament 5 plugins](https://filamentphp.com/docs/5.x/plugins/panel-plugins),
[Spatie PermissionRegistrar](https://raw.githubusercontent.com/spatie/laravel-permission/main/src/PermissionRegistrar.php),
[Bouncer implementation](https://raw.githubusercontent.com/JosephSilber/bouncer/master/src/Bouncer.php),
[Laravel 13 authorization](https://laravel.com/docs/13.x/authorization),
[Symfony voters](https://symfony.com/doc/current/security/voters.html).
Explicit modes and code-owned roles are project decisions. No upstream implementation was vendored or added
as a runtime dependency. Moving branches were observed on the review date, not promised as version-pinned APIs.


### 2026-09-30 — точечное уточнение CRM public API

- [Laravel Eloquent](https://laravel.com/docs/13.x/eloquent): fresh Builder/record lookup как основа ProjectScope.query/resolve.
- [PHP attributes](https://www.php.net/manual/en/language.attributes.syntax.php): method-target attributes;
  обязательный Decides — собственное решение пакета. Итог: [D84](../audits/2026-09-29-audit/opus/02-decisions.md#d84).
