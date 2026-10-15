# Evidence к аудиту API freeze

Исходный HEAD: `2ce692f74c210e81426e9367303701516b1cc3f0`. Итоговая интерпретация результатов: [VALIDATION.md](../VALIDATION.md). Findings: [REPORT.md](../REPORT.md). Исправленная приёмка: [IMPLEMENTATION.md](../IMPLEMENTATION.md).

Для штатных checks пара `<label>.json` / `<label>.log` содержит command argv, cwd, HEAD, UTC start, exit code, duration и полный stdout/stderr. Создаёт их [run-check.py](run-check.py); команда исполняется без shell interpolation. Он фиксирует testing/SQLite-memory env для обычных запусков. [run-db-check.py](run-db-check.py) отдельно проверяет и задаёт фактический dedicated server target перед штатным DB runner. Credentials не записываются в evidence.

Использовать окончательные результаты с суффиксом `-refreshed`, `-effective` или `-final`, когда они существуют. Более ранние файлы сохранены для прозрачности environment failures и усиления probes. В частности, ранний Filament probe содержал18 assertions/синтетические25 SELECT; final содержит24 assertions и40 SELECT на25 настоящих grants. Ранний compatibility probe имел3 cases; final имеет4. Это не разные product versions.

## Карта воспроизведений

| Findings | Исходник | Итоговое наблюдение |
|---|---|---|
| R01/R02/R07/R08/R09 | [authorization/repro.php](authorization/repro.php) | [matching-grants](repro-matching-grants.log), [structural](repro-structural-resolver.log), [model](repro-given-model.log), [numbers](repro-batch-numbers.log), [binary](repro-batch-fields.log), [directory](repro-directory-resolver.log) |
| R03–R06 | [storage/reproduce.php](storage/reproduce.php) | [final log](repro-storage-final.log), [observed JSON](storage/observed.json) |
| R10–R13 | [filament/FilamentProbeTest.php](filament/FilamentProbeTest.php) | [final log](repro-filament-final.log), [JUnit](filament/probes.junit.xml) |
| R18 | [filament/enum-marks-probe.php](filament/enum-marks-probe.php) | [невалидный результат генератора](filament/generated-widget-permission.php), [lint failure](repro-enum-lint.log) |
| R14 | Прочитанные manifests/source | [surface inventory](delivery/supported-surface.json) |
| R15 | [delivery/compatibility-blind-spots.php](delivery/compatibility-blind-spots.php) | [final log](repro-compatibility-final.log), [JSON](delivery/compatibility-blind-spots.json) |
| R16 | Commit/plan/evidence cross-check | [traceability JSON](delivery/plan-traceability.json) |
| R17 | PHPStan/Rector/type coverage | См. [таблицу checks](../VALIDATION.md) |

## Повторение без изменения production

Из корня репозитория после обычного dev dependency install:

```bash
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php matching-grants
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php structural-resolver
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php given-model
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php batch-numbers
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php batch-fields
php audits/2026-10-15-api-freeze/evidence/authorization/repro.php directory-resolver
php audits/2026-10-15-api-freeze/evidence/storage/reproduce.php
python3 audits/2026-10-15-api-freeze/evidence/run-check.py repro-filament-recheck php -d memory_limit=1G vendor/bin/phpunit audits/2026-10-15-api-freeze/evidence/filament/FilamentProbeTest.php --colors=never --no-coverage
php audits/2026-10-15-api-freeze/evidence/delivery/compatibility-blind-spots.php
php audits/2026-10-15-api-freeze/evidence/filament/enum-marks-probe.php
php -l audits/2026-10-15-api-freeze/evidence/filament/generated-widget-permission.php
```

Последняя команда на проверенном HEAD должна вернуть255: это предмет R18. Пробы используют существующие fixtures/internal helpers для bootstrap, затем проверяют настоящие public consumer paths; они не заявляются examples supported testing API. Authorization/storage scripts принудительно выбирают и проверяют memory DB. Filament запускать с `run-check.py` либо с указанным в part явным testing env.

После исправления production сохранённые observations не перезаписывать задним числом. Новые results писать под новым именем/новым audit run и переносить исправленные assertions в штатную suite. Metadata отражает время запуска, а не дату изменения будущей реализации.

Артефактный [validate.py](../validate.py) проверяет локальные ссылки, JSON, Python/PHP probe syntax и SVG. Исключение ровно одно: известный невалидный результат R18 обязан падать с duplicate-import, а не произвольной ошибкой. Graph outputs регенерируются [diagrams/render.py](../diagrams/render.py), исходник — [graphs.json](../diagrams/graphs.json).
