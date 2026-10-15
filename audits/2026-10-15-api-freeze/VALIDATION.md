# Проверки аудита и границы доказательств

Дата: 2026-10-15. Проверяемый commit: `2ce692f74c210e81426e9367303701516b1cc3f0`. Команды запускались из корня azguard. Production и штатные tests не менялись. Полные выводы и metadata каждой проверки находятся в [evidence](evidence/README.md); exit codes сохранены, не заменены оценкой агента.

**Общий результат не GREEN:** функциональные наборы и coverage прошли, но обязательные PHPStan/Rector завершились ошибкой, а стандартный type-coverage запуск выдал недостоверные 100%. Отдельные probes воспроизвели ошибки авторизации и других контрактов. Успешные suite checks не закрывают эти findings.

## 1. Фактическая среда

- PHP в PATH: Herd 8.4.1, static build без dynamic extension loading. Для line coverage repository helper выбрал системный PHP 8.4.26 с coverage driver. Это два runtime, а не единая версия PHP для всех результатов.
- Composer 2.8.3; Laravel 13.33.0, Filament 5.8.4, Livewire 4.4.6, Testbench 11.2.0, Pest 4.7.8, PHPStan 2.2.15, Larastan 3.12.2, Rector 2.6.7, PAO 1.1.5. [Записанные версии и autoload](evidence/dependency-versions.json).
- SQLite `:memory:` для обычных тестов/probes. Существующие отдельные Docker test services: PostgreSQL 16.14, MySQL 8.4.10, MariaDB 10.11.19. Использована только подтверждённая `azguard_test`, не приложение и не production.
- Redis: отдельный test service на host port 26379; штатная fixture использует DB15 и собственный случайный prefix, очищает собственные keys. `FLUSHDB`/`FLUSHALL` не запускались.
- Рабочее дерево изначально чистое по tracked files. По окончании новые артефакты только в `audits/2026-10-15-api-freeze/`; ignored vendor/lock обновлялись для исправления autoload, также созданы обычные ignored build/cache artifacts.

Эта таблица фиксирует установленные версии. Это не утверждение о latest upstream versions и не полная supported matrix PHP/Laravel.

## 2. Положительные проверки

| Проверка | Команда / lane | Наблюдаемый результат | Evidence |
|---|---|---|---|
| SQLite | `composer test` после refresh | **4 919 tests, 396 051 assertions**, 94.5 s | [log](evidence/sqlite-suite-refreshed.log), [metadata](evidence/sqlite-suite-refreshed.json) |
| Line coverage, включая extended | `composer check:coverage` | **4 921 tests, 416 080 assertions**, **93.7%**, min85 выполнен; 673.7 s | [log](evidence/line-coverage.log), [metadata](evidence/line-coverage.json) |
| PostgreSQL обычная server lane | `run-db-check.py pgsql` | 762 tests / 3 894 assertions; seeds1,2 | [log](evidence/postgres-lane-effective.log) |
| MySQL обычная server lane | `run-db-check.py mysql` | 762 / 3 894; seeds1,2 | [log](evidence/mysql-lane-effective.log) |
| MariaDB обычная server lane | `run-db-check.py mariadb` | 762 / 3 894; seeds1,2 | [log](evidence/mariadb-lane-effective.log) |
| PostgreSQL engines/races | `run-db-check.py pgsql --engines` | 71 / 2 670 | [log](evidence/postgres-races.log) |
| MySQL engines/races | `run-db-check.py mysql --engines` | 71 / 2 670 | [log](evidence/mysql-races.log) |
| MariaDB engines/races | `run-db-check.py mariadb --engines` | 71 / 2 728 | [log](evidence/mariadb-races.log) |
| Redis | `REDIS_PORT=26379 composer test:redis` | 2 / 26 | [log](evidence/redis-lane.log) |
| Core-only consumer archive | `bash bin/consumer-fixture.sh core-only` | fresh app boot/migrate/doctor/grant/check, modules отсутствуют | [log](evidence/consumer-core-only.log) |
| Core + Filament consumer archive | `bash bin/consumer-fixture.sh --with-filament` | install, HTTP suite 5/9, RefreshDatabase suite 4/65, Filament boot/config smoke | [log](evidence/consumer-filament.log) |
| Pint | `composer lint:check` | exit0 | [log](evidence/lint.log) |
| API freshness | `composer api:check` после refresh | exit0 | [log](evidence/api-manifest-refreshed.log) |
| Compatibility with baseline | `composer api:compat` | exit0, baseline `3d789538…`; R15 показывает предел этой проверки | [log](evidence/api-compatibility.log) |
| Документация | `composer docs:language`, `composer docs:php`, `npm run docs:build` | все exit0 | [language](evidence/docs-language.log), [PHP](evidence/docs-php.log), [build](evidence/docs-build.log) |

Обычный SQLite набор исключает engines/redis/replica/extended. Coverage gate исключает engines/redis/replica, **не** extended: два scale-теста действительно выполнены. Counts разных lanes перекрываются и не суммируются как уникальные тесты. Coverage — измерение строк штатным инструментом, не доказательство правильности всех комбинаций SPI.

Server wrapper запускает штатный `bin/test-db.php`, а не свою реализацию test suite. Перед запуском проверяет healthy Compose service, фактический порт/credentials действующего контейнера, `APP_ENV=testing`, имя БД `azguard_test` и `SELECT 1` через Laravel. Secrets не печатаются. Штатный TestCase сбрасывает таблицы только этой выделенной тестовой БД. В metadata outer `run-check.py` указан SQLite env: `run-db-check.py` устанавливает и проверяет действительный server target внутри процесса; это видно в соответствующем логе.

## 3. Красные и недостоверные проверки

| Проверка | Наблюдение | Как трактовать |
|---|---|---|
| `composer analyse` | exit1; public `InteractsWithAzGuard` used zero times, `trait.unused` | **FAIL**; API trait не проанализирован выбранной конфигурацией |
| PHPStan без PAO и parallel | `PAO_DISABLE=1 php … phpstan analyse --debug --no-progress`, exit1 с той же единственной trait error | Подтверждает воспроизводимую проблему analysis coverage, не только extension warning |
| PHPStan на системном PHP | worker terminated by SIGSEGV, exit1 | **FAIL environment/runtime**, не зелёная проверка и не доказанный OOM; причина сигнала не установлена |
| `composer refactor:check` | exit1: skip содержит отсутствующий `Rector\\Php80\\Rector\\Ternary\\TernaryToNullsafeCoalesceRector` | **FAIL tooling/config** на допустимом установленном Rector 2.6.7 |
| `composer test:types` | exit0, десятки warnings о foreach(null), итог 100% только по Visibility | **INVALID measurement**; exit0 не засчитан как полная проверка |
| Последовательный type coverage без PAO | exit0, 99.9%, JSON содержит 479 файлов | Контрольное измерение в явно другом режиме; не исправляет штатную команду |

Логи: [PHPStan](evidence/static-analysis-refreshed.log), [debug](evidence/static-analysis-debug.log), [system PHP](evidence/static-analysis-system-php.log), [Rector](evidence/rector.log), [обычный type coverage](evidence/type-coverage.log), [контроль](evidence/type-coverage-serial.log), [479 per-file results](evidence/type-coverage-serial-result.json).

Контроль запущен с `__PEST_PLUGIN_ENV=1 PAO_DISABLE=1`, `--no-cache` и `--type-coverage-json`. Причина сбоя отдельного worker не локализована до единственного компонента: несколько условий менялись одновременно. У контрольной цифры тоже есть граница: plugin исключает файлы по `str_contains(contents, 'trait ')` (`vendor/pestphp/pest-plugin-type-coverage/src/Plugin.php:142`). Поэтому в отчёт не попадают traits, а также `AzGuardPlugin.php`, где есть переменная `$trait = …`. Не называть 99.9% проверкой каждой строки/каждого PHP-файла репозитория. Исправление gate должно проверять полноту согласованного inventory и не принимать потерянные worker results за 100%.

## 4. Исправленные препятствия окружения

**Stale local Composer metadata.** До проверки installed path-package metadata содержали только `AzGuard\\ => src/` без PSR-4 prefixes новых modules. `composer test` падал на отсутствии AssignmentScopeFilter; `api:check` ошибочно видел потерю module types. После чтения package manifests выполнен targeted `composer update axiomasoft/azguard axiomasoft/azguard-filament rector/rector --no-interaction --no-progress --no-scripts`. Обновились metadata двух local path packages; Rector остался2.6.7. Tracked Composer manifests не менялись. [Refresh](evidence/dependencies-refresh.log); [исходный test failure](evidence/sqlite-suite.log), [исходный manifest failure](evidence/api-manifest.log). После исправления оба check повторены и прошли.

**Compose configuration drift.** В дереве объявлены параметры, отличающиеся от уже запущенных test services; первая попытка DB preflight не подключилась или не нашла предполагавшуюся test DB. На этой стадии DDL не выполнялся. Wrapper уточнён по фактическому healthy container state, подтверждён `azguard_test`, после чего lanes прошли. Исходные [PostgreSQL](evidence/postgres-lane.log), [MySQL](evidence/mysql-lane.log), [MariaDB](evidence/mariadb-lane.log) ошибки сохранены. Production-окружение и Compose deployment не менялись.

**Instruction drift.** Локальный AGENTS.md azguard несёт umbrella instructions swissknifeman о Python packages/core/marketplace. Это несоответствие было сообщено пользователю; произвольные описания той структуры не использовались как факты о PHP-репозитории. Generated projection в рамках аудита не редактировалась. Исправление owning setup/generator — отдельная задача окружения.

Herd сообщает unsupported dynamic loading для предлагаемой turbo extension PHPStan; это сохранено в исходных logs. Обходы среды не замаскированы под исходный green. Repository `composer check` целиком не заявляется пройденным: отдельные обязательные составляющие красные.

## 5. Воспроизведения дефектов

| Группа | Что реально запущено | Результат |
|---|---|---|
| Authorization | 7 CLI cases в отдельных memory databases; root повторил все 6 defect cases | SQL/scalar, batch/scalar, held-model helpers и directory расхождения подтверждены |
| Storage/Audit/fields | 4 сценария, public grant path, реальные prune команды, 2 memory connections | Все четыре наблюдения подтверждены; [final log](evidence/repro-storage-final.log) |
| Filament | PHPUnit 5 tests, **24 assertions**, реальные Livewire components/actions | Все probes воспроизводят ошибки; [final log](evidence/repro-filament-final.log) |
| Enum generator | Production EnumMarks генерирует файл; `php -l` | Генерация exit0, lint **exit255**, duplicate import; [log](evidence/repro-enum-lint.log) |
| Compatibility guard | Реальные generator/comparator functions, isolated PHP consumers и Symfony input | Все 4 blind spots воспроизведены; [final log](evidence/repro-compatibility-final.log) |

Здесь exit0 означает «проба исполнена и поведение наблюдалось», а не «продукт исправен». Невалидный `generated-widget-permission.php` намеренно сохранён как результат текущего генератора; его нельзя включать в общий lint артефактов с ожиданием success. Подробная причинная цепочка, строки исходников и исправленная acceptance находятся в соответствующей части отчёта.

## 6. Что этим аудитом не доказано

- Не выполнены все сочетания PHP 8.3/8.4/8.5 × Laravel11/12/13 и в частности новый Laravel11×PostgreSQL regression из P4.9. Текущие DB результаты на Laravel13 его не заменяют.
- Не запускался настоящий Octane worker или production queue deployment; прочитаны lifetimes и существующие integration tests. Не доказана гипотетическая Octane утечка.
- Не поднималась replica profile и не выполнялся replica lane. Код freshness policy и имеющиеся тесты прочитаны; готовность real replica после будущих изменений требует своей qualification.
- Server lanes использовали штатные seeds1,2; `--full` серверный parity sweep не выполнен. Обычный SQLite suite и coverage/extended выполнены полностью в своих группах.
- Не выполнялся полный mutation campaign; сообщение test fixture о mutation-gate в coverage log не является результатом такого campaign.
- Не измерена end-to-end latency production UI и не делалось сравнение с0.7. R13 — измеренные 40 одинаковых SELECT, без выдуманного выигрыша в миллисекундах.
- Не выполнялись tag, split/push, Packagist публикация. Archive-consumer проверяет local package archives; внешний release delivery остаётся D27.

Ссылки и синтаксис report artifacts, JSON и SVG проверяются отдельно через `python3 audits/2026-10-15-api-freeze/validate.py`. Эта команда не запускает tests, не изменяет БД и не делает runtime findings зелёными.
