# P3 — evidence исполнения

## P3.1 — GREEN

| 08 §5 | Реализация | Проверка |
|:--|:--|:--|
| 1: lock состояния первым | Storage::lockPanel, сортировка в mutate; SQLite INSERT первым | MutateConcurrencyTest, MutateSqliteFileTest, MutateDeadlockTest |
| 2: работа под lock | Storage::perform | MutateTest, rollback |
| 3: строки и bump атомарны | pendingTouches на корневой mutate, rollback снимка вложенного поддерева | MutateTest: nested subtree, два touch, no-op |
| 4: root commit публикует | Connection::afterCommit; закрытие handle в finally | MutateTest: outer commit/rollback, escaped handle, publication failure |
| 5: bounded retry | DetectsConcurrencyErrors, 3 попытки с jitter, одна попытка при внешней транзакции | retry/exhaustion/nested тесты; MutateDeadlockTest — реальный deadlock, mutate-жертва, 2 транзакционные попытки |

V44: 50 worker-процессов на каждой серверной СУБД, версия 50, максимум попыток 1 (массивы в artifacts/P3-execution).
V52: adm_ на secondary завершается до коммита удерживаемой azg_ панели (timeout 2s).
V97: no-op, all-or-nothing, повтор без лишней версии/колбэка.
V99-часть: state читает предыдущую версию пока TransactionCommitting удерживает UPDATE.
V101-часть: deadlock и откат вложенного поддерева. Каждый серверный прогон: 3 теста, 10 assertions.

Targeted: 62 tests, 138 assertions; full: 1364 tests / 239635 assertions; Arch: 63 tests / 291 assertions.
Pint, PHPStan level 8, api-manifest check, diff check — exit 0. Type coverage 99.8%, Storage 100%.
Точные команды, логи и exit-коды: artifacts/P3-execution/P3.1-*. СУБД: PG 16.14, MySQL 8.4.10, InnoDB page size 16384.

Дефекты среды и исправления записаны в P3.1-environment.md: конфликт стандартных портов; turbo-extension на статическом PHP; лимит памяти Arch.
Ревью по указанию владельца от 2026-10-04 не запускалось. P3.3–P3.5 за пределами этой команды.

## P3.2 — предварительная находка

MySQL 8.4.10: `_ascii'a' COLLATE ascii_bin = _ascii'a ' COLLATE ascii_bin` → 1; information_schema.COLLATIONS: ascii_bin PAD SPACE.
D12 требует ascii_bin, а P3.2 и 08 §2 требуют различать хвостовые пробелы. Владельцу отправлен выбор: VARBINARY(n) с каноном ASCII в кодеке либо изменение тестовой приёмки при сохранении VARCHAR ascii_bin. Владелец выбрал VARBINARY; принято D13.

## P3.2 — GREEN

| 08 §2 | Колонки/ограничения | Подтверждение на SQLite / PG / MySQL / MariaDB |
|:--|:--|:--|
| permissions | bigint id, panel ID64, tenant_key ID200, tenant_type ID128 nullable, tenant_id HK nullable, name ID255; label/group 191, description text, meta JSON, UTC dateTime | StorageSchemaTest / StorageConstraintsTest; полные getColumns в снимках |
| permissions UNIQUE | panel + tenant_key + name, pm_identity | getIndexes; полный дубликат отвергнут, регистр и хвостовой пробел различаются |
| role_grants | panel, tenant, role ID64, subject_type ID128 + HK, context, origin ID128 default manual; expires/actor/reason/meta/time | StorageSchema::create; role_grants в каждом DDL-снимке для string/uuid/bigint/ulid |
| permission_grants | те же поля, permission ID255 вместо role | permission_grants string в снимках; полный unique создан на InnoDB 16KiB |
| grants UNIQUE | panel + tenant_key + role/permission + subject_type + subject_id + context_key + origin | rg_identity / pg_identity; разные origin и tenant допустимы, дубликат отвергнут |
| grants INDEX | subject tuple, context tuple, origin tuple, expires_at | rg/pg_subject, context, origin, expiry; getIndexes без префиксных индексов |
| tenant/context CHECK | key global ⇔ обе NULL; type NULL ⇔ id NULL | PG/MySQL/MariaDB CHECK; SQLite BEFORE INSERT/UPDATE triggers; отказ на полупаре и ошибочном key; успешная полная пара |
| panel_state | panel ID64 PK ps_pk, version bigint default 0, incarnation ID26, updated_at UTC dateTime | DDL + mutate engine regression (включая real deadlock) |
| storage_state | smallint id PK ss_pk, singleton id=1, schema JSON с version/identity_codec/storage_id/prefix/host_keys | singleton CHECK/trigger; StorageStateTest missing table/row, changed config и каждый schema discriminator |
| ID(n), HK | PG VARCHAR COLLATE C/native UUID; SQLite BINARY; MySQL/MariaDB VARBINARY по D13; bigint native | getColumns с collation; uuid/bigint/ulid варианты; HostKeyColumns канон ASCII |
| no definition tables / no FK | permissions/role_grants/permission_grants/panel_state/storage_state; без roles/role_permissions/role_contexts | V105-часть: SchemaAssertions; DdlSnapshot getForeignKeys=[] (V113-часть) |

Миграции: default up/down загружается через provider и публикуется azguard-migrations; stub(default) побайтно равен миграции ядра.
Генератор создаёт own один раз, не перезаписывает, отказывает default/unknown; сгенерированная миграция реально исполнена up/down.
TwoStoragesTest: azg_/adm_ создаются, изменяются и удаляются независимо. Имена ≤63 байт; максимальный prefix проверен.
DDL-снимки tests/Fixtures/Storage/ddl/{sqlite,pgsql,mysql,mariadb}.json с колонками, индексами, CHECK/триггерами, collation, FK; сверка без AZGUARD_UPDATE_SNAPSHOTS.
СУБД: SQLite 3.43.2, PG 16.14, MySQL 8.4.10, MariaDB 10.11.19. InnoDB 16384 байт; таблицы inherit utf8mb4 (mysql unicode_ci, mariadb general_ci), identity columns VARBINARY без collation.
Engine suites: PG 5 tests / 142 assertions; MySQL 5 / 142; MariaDB 5 / 198. 50 workers на каждой СУБД, 50 bumps, максимум 1 попытка; deadlock-жертва 2 транзакционные попытки.
Все обязательные product checks exit 0 (P3.2-*.log/.exit). Известные исторические plan-lint ошибки P0/P1 вне зависимостей P3; проверяется delta.
Дефекты среды: P3.2-environment.md. Принятое владельцем уточнение VARBINARY — D13; ревью не запускалось.
