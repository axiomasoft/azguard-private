# Проверенные первичные источники

Проверено напрямую 2026-09-29 через официальные сайты/репозитории. Это **не** доказательство утверждений Perplexity целиком: ниже только факты, на которые опирается [дополнительный аудит](../additional-audit.md). Версионные ветки меняются; для исполнения проверять зафиксированную версию зависимости. Точные Composer-манифесты официальных веток записаны отдельно в [dependency-manifests.json](dependency-manifests.json) с SHA-256 ответов.

| Источник | Проверенный факт и граница использования |
|:--|:--|
| [Laravel 13 — container scoped](https://laravel.com/docs/13.x/container#binding-scoped-singletons) | Scoped экземпляры сбрасываются между request/job lifecycles. Документация не обещает fiber-local isolation внутри одного lifecycle. |
| [Laravel 13 — authorization](https://laravel.com/docs/13.x/authorization#intercepting-gate-checks) | Ненулевой результат `Gate::before` становится результатом проверки; `allowIf`/`denyIf` обходят before/after hooks. |
| [Laravel 13 — Eloquent events](https://laravel.com/docs/13.x/eloquent#events) | Mass update/delete не вызывают события для отдельных моделей. Это не покрывает автоматически все другие bypass paths: их нужно проверить в коде. |
| [Laravel 13 — read/write connections](https://laravel.com/docs/13.x/database#read-and-write-connections) | Laravel поддерживает разные read/write hosts и `sticky` после записи **в том же request**; это не гарантия свежести чтений на другом worker. |
| [Laravel 13 — after-commit events](https://laravel.com/docs/13.x/events#dispatching-events-after-database-transactions) | `ShouldDispatchAfterCommit` откладывает dispatch до commit, а при rollback событие отбрасывается. Durable delivery через crash не обещана этим механизмом. |
| [Laravel 13 — package development](https://laravel.com/docs/13.x/packages) | Provider discovery, config/migration publishing/loading — штатные package hooks. |
| [Laravel 13 — cache](https://laravel.com/docs/13.x/cache) | Backend имеет TTL/lock semantics; не заменяет проверку absolute grant expiry. |
| [Laravel 13 — Octane](https://laravel.com/docs/13.x/octane) | Worker process reused; долгоживущее mutable state требует явного lifecycle contract. |
| [Laravel 13 — release notes](https://laravel.com/docs/13.x/releases) | Laravel 13 требует PHP 8.3+. Версии Testbench/Filament сверены по manifest snapshot, не выведены из этого факта. |
| [PHPStan — internal symbols](https://phpstan.org/writing-php-code/phpdocs-basics#internal-symbols) | Описана проверка usage вне **top namespace** (в PHPStan 2.1.13 + Bleeding Edge); один корень `AzGuard` для разных Composer packages требует отдельного package-boundary rule. |
| [Roave BC Check](https://github.com/Roave/BackwardCompatibilityCheck) | Сравнивает API двух revisions; поведенческие контракты AzGuard остаются отдельными fixtures/tests. |
| [Filament 5 — Resource overview](https://filamentphp.com/docs/5.x/resources/overview) | Resources — интерфейс к Eloquent models; `getEloquentQuery()` задаёт базу resource queries. Это ограничивает буквальную замену storage при сохранении native Resource. |
| [Filament 5 — create records](https://filamentphp.com/docs/5.x/resources/creating-records) | `handleRecordCreation()` — documented hook для custom write logic. |
| [Filament 5 — edit records](https://filamentphp.com/docs/5.x/resources/editing-records) | Есть отдельные hooks для edit/update. Проверять installed minor при детальном redesign. |
| [Filament 5 — panel transactions](https://filamentphp.com/docs/5.x/panel-configuration#enabling-database-transactions) | По умолчанию Filament не оборачивает операции в DB transaction; opt-in API `databaseTransactions()`. |
| [Pest mutation testing](https://pestphp.com/docs/mutation-testing) | `--covered-only` генерирует mutants только на покрытых строках; `--ignore` исключает классы. Поэтому score имеет ограниченный denominator. |
| [Composer path repositories](https://getcomposer.org/doc/05-repositories.md#path) | Path packages могут устанавливаться symlink/mirror; один root environment не доказывает published consumer installation. |
| [Composer version constraints](https://getcomposer.org/doc/articles/versions.md) | `^0.3` остаётся внутри 0.3-линии; фиксировать actual package support нужно через resolver fixtures. |
| [PostgreSQL 16 unique indexes](https://www.postgresql.org/docs/16/indexes-unique.html) | Обычный unique допускает несколько NULL; `NULLS NOT DISTINCT` меняет семантику. |
| [PostgreSQL 16 isolation](https://www.postgresql.org/docs/16/transaction-iso.html) | В Read Committed каждое чтение видит свой statement snapshot; утверждение о linearizable end-to-end decision требует большего, чем revision key. |
| [MySQL 8.4 CREATE INDEX](https://dev.mysql.com/doc/refman/8.4/en/create-index.html) | Functional key parts специфичны для конкретной версии MySQL; переносимость на MariaDB не следует автоматически. |
| [SQLite CREATE INDEX](https://sqlite.org/lang_createindex.html) | Expression indexes и NULL uniqueness имеют собственные правила. |
| [PSR-11](https://www.php-fig.org/psr/psr-11/) | Стандарт рекомендует не превращать контейнер в service locator внутри доменных объектов. |
| [PSR-16](https://www.php-fig.org/psr/psr-16/) | Cache implementation вправе выселить item раньше TTL; expiry в cache backend не является бизнес-дедлайном grant. |
| [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html) | Deny-by-default и проверки на каждом relevant access path задают threat-model ориентир, не готовый API AzGuard. |
| [OWASP Multi Tenant Security](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html) | Client-selected tenant ID — селектор, не доказательство membership; связывать контекст с verified principal и текущими правами. |
| [Cedar authorization algorithm](https://docs.cedarpolicy.com/auth/authorization.html) | Permit/forbid composition и diagnostic errors формализованы; Cedar выбирает skip-on-error, который AzGuard не обязан повторять. |
| [Symfony voters](https://symfony.com/doc/current/security/voters.html) | Affirmative, consensus, unanimous и priority — разные decision strategies с разным смыслом deny/abstain. |
| [OpenFGA consistency modes](https://openfga.dev/docs/interacting/consistency) | Более сильная consistency имеет latency/throughput цену. Используется только как архитектурный контраст, не как рекомендация внешнего сервиса. |
| [Zanzibar paper](https://research.google/pubs/zanzibar-googles-consistent-global-authorization-system/) | Глобальные relation tuples/consistency tokens принадлежат другой операционной архитектуре; прямое внедрение не выводится из проблем AzGuard. |
| [Spatie wildcard docs v8](https://spatie.be/docs/laravel-permission/v8/basic-usage/wildcard-permissions) | У Spatie своя opt-in wildcard grammar; она не определяет каноническую грамматику AzGuard. |

## Проверка актуальных dependency branches

- [Filament panels 5.x manifest](https://github.com/filamentphp/filament/blob/5.x/packages/panels/composer.json): `php:^8.2`; зависимость на Filament support через `self.version`.
- [Filament support 5.x manifest](https://github.com/filamentphp/filament/blob/5.x/packages/support/composer.json): `illuminate/contracts:^11.28|^12.0|^13.0`, Livewire `^4.4.2` в просмотренной ветке.
- [Pest 4.x manifest](https://github.com/pestphp/pest/blob/4.x/composer.json): `php:^8.3.0`, PHPUnit `^12.5.33` в просмотренной ветке.
- [Testbench 9.x](https://github.com/orchestral/testbench/blob/9.x/composer.json), [10.x](https://github.com/orchestral/testbench/blob/10.x/composer.json), [11.x](https://github.com/orchestral/testbench/blob/11.x/composer.json): соответствующие ветки требуют Laravel 11, 12, 13 с конкретными **минимальными patch** версиями. Поэтому «поддержка всего minor range» не следует из общего `^11|^12|^13`.

Проверка веток не заменяет Composer resolution конкретного release tuple. Изменение ветки после этой даты не меняет сохранённый смысл проверки; hash snapshot позволяет выявить drift.
