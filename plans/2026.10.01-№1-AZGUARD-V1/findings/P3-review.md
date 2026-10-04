# Review P3 — 2026-10-04

Независимый reviewer: **Grok 4.7 / high / 500k**. Исполнитель Task lifecycle и live-гейтов — root Codex. Авторизован только read-only P3.5; продуктовых исправлений не было.

```json
{
  "session_id": "01a10581-c561-70a0-bd0d-64aba5c09c45",
  "current_model_id": "grok-4.7",
  "reasoning_effort": "high",
  "context_window": 500000,
  "created_at": "2026-10-04T06:02:33.187484076Z",
  "updated_at": "2026-10-04T06:33:27.272727071Z",
  "head_commit": "df2e034b30b828c35eea1eac76e9490731f24ed3",
  "source": "grok-provider-session-summary"
}
```

**Verdict: GREEN.** Blocker и major нет. R1 снят: это ложное срабатывание из-за неверной семантики PHP. R2 остаётся minor. Продукт после прошлого отчёта не менялся, прежние зелёные гейты root остаются в силе.

## Поправка R1

Первый отчёт утверждал, что `(bool) "0"` равно `true`, и поэтому `GrantFields::toRow()` записывает принятую Laravel ложь как истину. Это неверно. В PHP строка `"0"` приводится к `false`.

Живое доказательство root, прочитанное сейчас:

- `/tmp/azguard-p35/php-bool.log`: `false => false`, `true => true`, `0 => false`, `1 => true`, `"0" => false`, `"1" => true`.
- `/tmp/azguard-p35/boolean-probe/BooleanReviewTest.php` вызывает настоящие `GrantFields::for()` и `toRow()` под Testbench SQLite. Для скалярного и `multiple()` поля проверяются все шесть представлений, которые принимает правило `boolean`. Отдельно ожидается `"0" -> false` и `["0", "1"] -> [false, true]`.
- `/tmp/azguard-p35/boolean-probe.log`: passed, 1 тест, 14 assertions, exit 0.

Код `GrantFields.php:170-172` делает `(bool) $value` после правила `boolean`. Для `'0'` это `false`, для `'1'` это `true`. Тест `GrantFieldsTest` на `'enabled' => '1'` с этим согласован. Находка R1 отзывается. Продуктовый ремонт не требовался и не делался.

## Находки

| ID | Severity | Место | Нарушенный источник | Owning item |
|:--|:--|:--|:--|:--|
| R2 | minor | `packages/core/src/Storage/Schema/StorageSchema.php:65`, `:72` | P3.2 Implementation Rules и D12 п.6: имена `{prefix}{ps\|ss}_<смысл>` | P3.2 |

### R2 — первичные ключи не получают короткие имена

Оценка прежняя, подтверждена повторно.

`StorageSchema` передаёт `$prefix.'ps_pk'` и `$prefix.'ss_pk'`. `PostgresGrammar::compilePrimary()` пишет `add primary key` без этого имени. MySQL-грамматика тоже не подставляет имя, SQLite откладывает первичный ключ на создание таблицы. Снимки фиксируют фактические имена: PostgreSQL `azg_panel_state_pkey` и `azg_storage_state_pkey`, MySQL `primary`, SQLite `sqlite_autoindex_azg_panel_state_1`.

Уникальные индексы `pm_identity`, `rg_identity`, `pg_identity` и CHECK/триггеры короткое имя получают. При префиксе из 20 символов автоимя `{prefix}permission_grants_pkey` короче 63 байт. Бюджет PostgreSQL не пробит, целостность ключа сохраняется. Не совпадает только адресное имя, которое требует P3.2.

## Гейты

Наборы в этой сессии не перезапускались. Журналы `/tmp/azguard-p35` те же: suite 2029 / 242965, pgsql 7 / 164, mysql 7 / 164, mariadb 7 / 220, deadlock 1 / 3, PHPStan 0, Pint и API exit 0, types 99.8%. Scratch arch RED по-прежнему ловит `RoleGrant::query()` из `Panels`. Версии: PostgreSQL 16.14, MySQL 8.4.10, MariaDB 10.11.19. Root сообщал пустой porcelain для `packages tests bin`.

## Покрытие девяти проверок

| # | Вопрос | Итог |
|:--|:--|:--|
| 1 | Блокировка, одна транзакция, один bump, no-op, after-commit, вложенный откат, повтор только в корне | Замечаний нет. |
| 2 | Нет записи мимо `mutate`; зоны `DB`/`Schema`/`Connection`; handle закрывается | Замечаний нет. R1 P3.4 закрыт. |
| 3 | DDL на четырёх снимках, D13, без таблиц ролей | Состав совпадает. R2 — имена PK. |
| 4 | Один канон host keys, неканоничное отклоняется | Замечаний нет. |
| 5 | `storage_state` и уникальность `(подключение, префикс)` | Замечаний нет. |
| 6 | Свои модели, атрибуты Laravel 13, поля, коллизии, `meta`, decision-поля | Замечаний нет. Прежняя R1 снята. |
| 7 | Запрет прямых записей не зависит от окружения и событий | Замечаний нет. |
| 8 | Arch RED, манифест D12 п.4, имена, D37, нет кодов задач в `src` | Замечаний нет. |
| 9 | Отложенное D12 п.8 и Consequences в Phase Context P4–P8 | Отражено. Реализации будущих фаз не проверялись. |

Проверка 1 по `Storage::mutate()` не менялась: сортировка до блокировки, свой цикл только в корне и только на `causedByConcurrencyError`, SQLite начинает с `insertOrIgnore`, bump один раз на панель и только у корня, колбэки через `afterCommit` и снимаются откатом Laravel, handle закрывается в `finally`.

Проверка 6: `'0'` и `'1'` сохраняются как boolean-ложь и boolean-истина. Коллизия полей панели называет оба происхождения. Неизвестный ключ и необъявленное decision-поле отвергаются. `inMeta` уходит в `meta`.

## Что прочитано в `phase.diff`

Файл `/tmp/azguard-p35/phase.diff` в этой сессии прочитан по hunk-ам, не по статистике. Покрыты `composer.json`, `docker-compose.yml`, добавления манифеста и участок перестановки вокруг моделей и `StorageSchema`, конфиг, миграция ядра, провайдер, `AzGuardConfig`, три исключения, команда миграции, `Panel` / `PanelBuilder` / `PanelCompiler` / `PanelRecipe`, начало `Field`, stub, `phpunit.xml`, arch-тесты, engine-тесты и worker, feature-тесты полей, моделей, записей, mutate, команды, реестра, схемы и `storage_state`, `DdlSnapshot`, `SchemaAssertions`, фикстуры, заголовки четырёх DDL JSON, `Pest.php`, `TestCase` и unit-тесты конфига, полей и host keys.

`SourceManager`, `AssignmentScopePhase` и `AssignmentScopeRuntime` в текущем манифесте остаются. Видимая в diff замена `SourceManager` на `StorageSchema` — сдвиг JSON, не удаление символа. Новые публичные символы те же: `Field`, `FieldTarget`, три модели, `StorageSchema`, три исключения. Внутренние классы хранилища отдельными символами не экспортированы.

Четыре тела DDL JSON внутри diff построчно не перечитывались: это те же снимки, чьи колонки, unique, CHECK и имена PK уже сверены по файлам фикстур. Нового расхождения этот проход не дал.

## Ограничения

Первый R1 был ложным выводом ревьюера и опровергнут воспроизведением root. Этот отчёт его не сохраняет. Наборы продукта повторно не гонялись. P1/P2 и реализации P4–P8 не пересматривались. `toBase()` / `getQuery()` по-прежнему вне строгого контракта P3.4 и в находки не входят.

## Текущие гейты исполнителя P3.5

Проверено root Codex на неизменённом product HEAD `df2e034b30b828c35eea1eac76e9490731f24ed3`. Независимый reviewer — отдельная Grok-сессия; root выполнял live Validation. Исходные журналы находятся в `/tmp/azguard-p35`; ниже сохранены существенные результаты и команды, чтобы отчёт не зависел от наличия временного каталога.

| Carrier | Наблюдаемый результат |
|:--|:--|
| `composer test` | GREEN: 2029/2029 tests, 242965 assertions, 14.658 s; `APP_ENV=testing DB_CONNECTION=sqlite`. |
| `docker compose up -d --wait postgres mysql mariadb` | GREEN: все 3 контейнера healthy; `PGSQL_PORT=25432 MYSQL_PORT=23306 MARIADB_PORT=23307`. |
| PG engines, `--fail-on-skipped` | GREEN: 7/7 tests, 164 assertions, 3.865 s; PostgreSQL 16.14. |
| MySQL engines, `--fail-on-skipped` | GREEN: 7/7 tests, 164 assertions, 10.401 s; MySQL 8.4.10. |
| MariaDB engines, `--fail-on-skipped` | GREEN: 7/7 tests, 220 assertions, 7.753 s; MariaDB 10.11.19. |
| `vendor/bin/pint --test` | GREEN, exit 0. |
| `vendor/bin/phpstan analyse --memory-limit=1G` | GREEN: 0 errors; `php -d phpstan.restarted=1` — ранее установленный обход static PHP/turbo дефекта. |
| `php bin/api-manifest.php --check` | GREEN, exit 0; манифест не изменён. |
| `php -d memory_limit=1G vendor/bin/pest --type-coverage --min=98` | GREEN, 99.8%; также `-d phpstan.restarted=1`. |
| `git status --porcelain -- packages tests bin` | GREEN: после исправленного независимого отчёта вывод пуст, exit 0; код, тесты и bin не менялись. |

Дополнительный живой witness: PostgreSQL `tests/Engines/MutateDeadlockTest.php --fail-on-skipped` — GREEN: 1 test, 3 assertions, 0.379 s; это повтор реального deadlock, а не симуляция исключения.


Точные engine-команды (host loopback, только `azguard_test`; TestCase отклоняет серверную БД без суффикса `_test`):

```bash
APP_ENV=testing DB_CONNECTION=pgsql PGSQL_HOST=127.0.0.1 PGSQL_PORT=25432 PGSQL_DATABASE=azguard_test php -d memory_limit=1G vendor/bin/pest --group=engines --fail-on-skipped
APP_ENV=testing DB_CONNECTION=mysql MYSQL_HOST=127.0.0.1 MYSQL_PORT=23306 MYSQL_DATABASE=azguard_test php -d memory_limit=1G vendor/bin/pest --group=engines --fail-on-skipped
APP_ENV=testing DB_CONNECTION=mariadb MARIADB_HOST=127.0.0.1 MARIADB_PORT=23307 MARIADB_DATABASE=azguard_test php -d memory_limit=1G vendor/bin/pest --group=engines --fail-on-skipped
```

### Повтор arch RED witness

В `/tmp/azguard-p35/arch-red` скопированы исходный witness и тест из `artifacts/P3-direct-writes/arch-red-model-static-{source,test}.php.txt`. Применён тот же `SourceScan::modelStaticCallsIn`, который использует production arch-правило. Ожидаемый RED: 1 test failed, 1 assertion; строка `/tmp/azguard-p35/arch-red/src/ForbiddenModelCall.php:4 AzGuard\Storage\Models\RoleGrant::query()` вместо пустого списка. Источник временного файла — namespace `AzGuard\Panels`; main product не менялся.

```bash
php -d memory_limit=1G vendor/bin/pest --test-directory=../../../../../tmp/azguard-p35/arch-red /tmp/azguard-p35/arch-red/ModelStaticTest.php
```
Первый вызов с абсолютным `--test-directory` дал инфраструктурный FatalException, поскольку Pest добавляет root к этому аргументу. Он не использован как RED witness; исправлен только scratch-launch, затем получено ожидаемое assertion failure.


### SHA-256 live journals

| Журнал | SHA-256 |
|:--|:--|
| `suite.log` | `258bcb2bc28ce2c130a08deac9979500181f49e129c17c3e864b2e2be60c78fb` |
| `pgsql.log` | `8741851327a1dd9e7b67fe74febbfcff68fb5e3eb1a251e56a9e21cdc7ff4a73` |
| `mysql.log` | `a2581240398430b07254dabca241f0b880e5f85d68de3d089e72774f61747a07` |
| `mariadb.log` | `66a6548af134160d22d97bfe06ef29c8f2f6ff87a6564ccbc1c7f387b850bdbd` |
| `phpstan.log` | `c10f74fb840de989bba7e9c2dfee7f8419b5d2ac0cc0daf18a4a187f6530aa43` |
| `pint.log` | `cd1a94fc2cf6a965b86e1a4809d6c7fb9148b1ee374e1010ed2ac96ff4876ec2` |
| `api.log` | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` |
| `types.log` | `d9ee10416842029c59104499db0c0fe227f4157b2aa1f8f19b4616e4218d6407` |
| `deadlock.log` | `44b9c47d7acaa50a9ce76077f50e18ef974333044d03f4b677d2f2890385d6ed` |
| `arch-red.log` | `d37ca71623efb8f8358c1c33243b0b2a51c72a3b4db1074bbe35bb3ec0a15545` |
| `db-preflight.txt` | `0a55dd11c113bd9a4aa07aa22f540b06f6e287d24ff9aa9cf8a387a9ab4c0592` |

## Доказательства и границы завершения

R2 воспроизводится на default-схеме: после `StorageSchema::create("default")` прочитать `getSchemaBuilder()->getIndexes("azg_panel_state")` и `getIndexes("azg_storage_state")`. На PostgreSQL первичные ключи имеют `azg_panel_state_pkey` / `azg_storage_state_pkey`, а не переданные `azg_ps_pk` / `azg_ss_pk`. Свидетельство: `StorageSchema.php:65,72`, установленный `PostgresGrammar::compilePrimary()` и `tests/Fixtures/Storage/ddl/pgsql.json:807,846`; snapshots подтверждены текущим engine-gate. Сценарий, источник и owning item указаны выше. Minor не меняет уникальность, порядок блокировки или бюджет ≤63 байт и по критерию P3.5 не блокирует GREEN. Уточнение имён/исключений для native PK остаётся в ведении P3.2; review их не меняет.

Первоначальный отчёт сохранён временно в `/tmp/azguard-p35/grok-report-initial.md`; его major R1 опровергнут. SHA-256 первоначального JSON: `159d3392331123efd3f9a69fae70f780571c6dca85f2dcc30540ebf671466dcf`. SHA-256 исправленного JSON: `8ae9cc9603018806790c4726255e1fcb0eebd905fa300fdbb6a92c5a1e133974`. Оба являются ответами одной Grok-сессии; новый product candidate или второй полный аудит не создавались. Дополнительная scratch-проверка — 1 test / 14 assertions, exit 0.

В начале два headless prompt завершились `permission_cancelled` на составном shell-чтении. Продолжение сохранило ту же сессию, модель, high и 500k; shell был удалён из toolset, дальнейший review использовал native read/grep/list_dir. Это дефект адаптации permissions запуска, не owner stop, не продуктовый RED и не успешное завершение ревью. Финальные ответы имеют `stopReason=end_turn`. Переключение окна на 500k выполнено штатным `/context-window 500k`; provider summary подтверждён до и после review.

Исторические 12 ошибок plan-lint P0/P1 остаются видимым foreign baseline из handoff. Closure/bookkeeping проверяется относительно product HEAD до P3.5; ни failed, skipped, ни unavailable гейт не объявлялся GREEN. Чужие `.gitignore`, `.swissknife.json`, `.grok/` сохранены.
