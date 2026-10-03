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
D12 требует ascii_bin, а P3.2 и 08 §2 требуют различать хвостовые пробелы. Владельцу отправлен выбор: VARBINARY(n) с каноном ASCII в кодеке либо изменение тестовой приёмки при сохранении VARCHAR ascii_bin. Решение пока не получено.
