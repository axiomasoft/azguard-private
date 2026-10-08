# Аудит выполненных P1–P6 с исправлениями — 2026-10-08

Активный план: `2026.10.01-№1-AZGUARD-V1` / PLAN2. База: `ba02a9a`, исходное рабочее дерево чистое. Область — реализованные P1–P6, включая замечания прежних review, неоднозначности контрактов и их зависимости. Прямое поручение владельца разрешает исправления; полные и длительные прогоны исключены.

**Результат: исправлены 25 подтверждённых групп проблем — 12 Major и 13 Minor.** В счёт входят runtime-дефекты, недостатки проверок и противоречия документации; подробности и тяжесть каждой группы приведены ниже. Исправления находятся в рабочем дереве, коммитов и публикации нет. P7/P8 не исполнялись.

Самые существенные нарушения: пакетная авторизация и visibility обходили custom resolver; непрогруппированный OR в relation расширял выдачу даже при `deny()`; вложенные изменения позволяли revoke/prune удалить уже заменённый или продлённый grant; обещанный RefreshDatabase не работал; установщик мигрировал с прежней конфигурацией. Новые регрессии проверяют именно эти сценарии.

## Реестр исправлений

Major здесь означает существенное нарушение принятого поведения или надёжности обязательной проверки, а не автоматически подтверждённую уязвимость для внешнего атакующего. Некоторые сценарии требуют custom PHP-расширения приложения.

| № | ID / тяжесть | Владелец | Что расходилось с ожиданием → исправление |
|---|---|---|---|
| 1 | P1/2-F1 · Major | P2.1/P2.2 | Допустимые panel id/prefix `0`, `7` превращались в int-ключи PHP и ломали строгие вызовы → восстановлен string на границах, `007` сохраняет самостоятельную идентичность. |
| 2 | P1/2-F2 · Minor | P2.5 | Fingerprint не учитывал kind/ability/resource model native Gate binding → cache теперь замечает изменение рецепта даже при прежнем build id. Обязанность обновлять build id сохраняется. |
| 3 | P1/2-F3 · Major | P1.1/P1.3 | Циклический array в fields/identity приводил к OOM процесса → циклы отклоняются доменной ошибкой; общие ациклические значения остаются допустимыми и отцепляются от ссылок. |
| 4 | P1/2-F4 · Minor | P2.5 | Невосстанавливаемый snapshot отображался как current, хотя boot переходил на live compilation → boot и diagnostics используют общую проверку содержимого и panel id. |
| 5 | P1/2-F5 · Major | P2.5/P2.8 | FolderSource терял владельцев одинакового role FQCN, обходя DuplicateRole/D10 → origins сохраняются до дедупликации, cross-owner collision отклоняется, same-owner повтор допустим. |
| 6 | S1 · Major | P6.7/P4.20 | Настоящий RefreshDatabase превращал разрешённые запросы в `source_error` → явный testing adapter распознаёт только исходную Laravel baseline-транзакцию, привязывает PDO/fiber/record и разделяет cache случайным namespace. |
| 7 | S2 · Major | P6.6/P4 | MariaDB race gate зависел от парсинга человекочитаемого InnoDB status → структурные lock-wait таблицы, точный connection/table и интервал 120 ms с учётом обновления snapshot. |
| 8 | S3 · Minor | P6.7 | Contract kit отвергал корректные dependent/stateful plugins и показывал чужой registry во время boot → companion baseline и наблюдение реального register→boot lifecycle собственного клона. |
| 9 | S4 · Minor | P6.7 | Контроль отсутствия SQL-записей пропускал DML после комментария/CTE → общий классификатор, проверки реальных записей и отрицательных read-only примеров. |
| 10 | P4-A1 · Major | P4.9 | `decideMany()` давал Granted, когда custom scope resolver в scalar возвращал null/ошибку → fast path только для inherited resolver; override вызывается для каждой уникальной области. |
| 11 | P4-A2 · Major | P4.22/P4.12/P4.23 | OR/UNION в native relation или deferred scope обходил корреляцию EXISTS и deny → проверяется окончательное дерево подзапросов до изменения caller builder; явно сгруппированные условия поддержаны. |
| 12 | P4-A3 · Major | P4.12/P4.23 | Bounded visibility fallback включал отвергнутые custom resolver области → проверяются результат, ref/tenant/record; исключения дают fail-closed. |
| 13 | P5-A1 · Minor | P5.2 | Pipe мог поймать ошибку второго next и сохранить первый успех → счётчик сохраняет нарушение exactly-once и отменяет mutation. |
| 14 | P5-A2 · Major | P5.2/P5.3 | Выбранный revoke id после nested revoke+regrant удалял новую строку → финальная locked validation сравнивает id и откатывает stale selection. |
| 15 | P5-A3 · Major | P5.4/P5.2 | Prune удалял grant, продлённый nested pipe после выборки → expiry фиксирует fingerprint и проверяет его перед записью; stale batch целиком откатывается. |
| 16 | P5-A4 · Minor | P5.1 | Пустой permission batch обходил проверку конфликтующих panel selectors → panel разрешается и для пустого набора. |
| 17 | P5-A5 · Minor | P5.1/P6.1 | P03 обещал проверять HasAzGuard, но оставлял его future после закрытия фазы → настоящая trait fixture и проверки прямого/scoped API до и после deny. |
| 18 | P6-A1 · Major | P6.5 | После записи config/env/provider install запускал migration/doctor со старым bootstrap → config cache проверенно очищается, команды получают свежий Artisan process и выбранное окружение. |
| 19 | P6-A2 · Minor | P6.5 | Ошибка генерации panel не останавливала дальнейшую migration → немедленный возврат ошибки до migrate/doctor. |
| 20 | P6-R1 · Minor | P6.2/P6.3 | Используемый illuminate/auth отсутствовал в require, отсутствие cron-парсера давало class-not-found → auth объявлен, cron предложен с понятной конфигурационной ошибкой. |
| 21 | P6-R2 · Minor | P6.2/P6.4 | Middleware aliases/groups/FQCN не приводились к одному виду: проверки дублировались, resolved exclusions не действовали → штатное разрешение Router перед точным сравнением. |
| 22 | P6-R3 · Minor | P6.8 | Генератор мог записать provider в чужой providers-массив или принять комментарий за регистрацию → токены и точный literal путь `return→panels→providers`. |
| 23 | CI-R1 · Major | P4.21/P0 | Готовый Redis job был отключён, обычные test/coverage/mutation команды включали чужие инфраструктурные группы → Redis job включён, группы разведены по соответствующим стендам. |
| 24 | P6-R4 · Minor | P6.9 | Arch gate падал из-за пустых неотслеживаемых legacy-каталогов → запрещены реальные файлы/ссылки, допускаются пустые каталоги; autoload-контроль сохранён. |
| 25 | DOC-R1 · Minor | P3/P6.6 | Устарели DEVELOPMENT и I5 «нет all-tenants», оставалось обещание непереносимого physical PK name → инструкции обновлены, pruning явно выделен как обслуживание, PK semantics зафиксированы D25. |

## Подробные воспроизведения и файлы

- [P1–P2: пять находок](p1-p2.md): значения/identity, registry/catalog, cache и происхождение ролей.
- [Storage, транзакции и testing kit: четыре находки](p3-storage.md): baseline, MariaDB observer, plugin contracts и SQL write observer.
- [P4: три нарушения авторизации/visibility](p4.md): примеры фактических лишних строк, fail-closed и атомарность отказа.
- [P5 и install: семь находок](p5.md): nested mutation, выборка grants, wrappers, P03 и свежий bootstrap.
- [P6 и сквозные проверки: шесть находок](p6.md): dependencies, HTTP, scaffolding, CI, arch и документация.

Отчёты содержат owning items, причины, RED/последующие PASS, изменения и конкретные ограничения. Уже исправленные до `ba02a9a` проблемы не выдаются за новые. Для части targeted проверок доказательство — сохранённый вывод инструментов в сессии и запись в отчёте; файловые логи указаны там, где они действительно сохранены.

## Проверка изменений без долгих прогонов

Наборы пересекаются: строки ниже нельзя складывать в число уникальных тестов. Все перечисленные итоговые прогоны завершились PASS; промежуточные ошибки сохранены отдельно и не засчитаны. Для SQLite проверены Testbench/TestCase и `:memory:`; MariaDB и Redis использовали уже запущенные изолированные тестовые службы, без запуска/перенастройки инфраструктуры.

| Проверка | Итог | Длительность / доказательство |
|---|---|---|
| Kernel/Panels/Catalog/Plugins/Folder | 1503 tests / 239268 assertions | 8.308 s, `p1-p2-tests.log`; до последней правки role origins |
| Catalog/folder/plugins после role origins | 233 / 865 | 1.112 s, `p2-role-origins-tests.log`; narrowing дополнительно 5 / 12 |
| Storage, production guards и kit | 701 / 3538 | 6.859 s, `p3-targeted.log` |
| Финальные RefreshDatabase regressions | 17 / 66 | 0.282 s, `p3-refresh-database-final.log` |
| Финальные contracts + testing kit | 131 / 504 | 1.188 s, `p3-contracts-final.log` |
| P4 affected bundle | 298 / 5612 | 4.000 s, вывод в сессии / `p4.md` |
| P5 affected bundle | 430 / 2647 | 4.940 s, вывод в сессии / `p5.md` |
| Install, включая два настоящих fresh bootstrap/DDL | 20 / 113 | 0.390 s, вывод в сессии / `p5.md` |
| Финальные Configuration/Http/Laravel/Diagnostics/PanelGenerator/Install | 259 / 895 | 2.481 s, `p6-final-tests.log` |
| Arch | 90 / 350 | 6.071 s, вывод в сессии; лимит 1G |
| MariaDB StateResetRace, один прогон | 3 / 26 | 2.511 s, `p3-mariadb-reset-race.log` |
| Реальный Redis, случайный prefix, DB15 | 2 / 26 | 0.153 s, вывод в сессии / `p6.md` |
| Scoped PHPStan, 30 изменённых source files | 0 errors | 6.11 s, `scoped-phpstan-final.log` |
| Pint всех 55 изменённых/новых PHP files | PASS | `pint-final.log` |

Дополнительно проверены оба Composer manifests, YAML workflow, синтаксис изменённых shell scripts, исключение Redis/replica из обычного запуска, API manifest и whitespace diff. Новые internal-классы не попали в public manifest. Выполнены два отдельных safety review: transaction baseline и финальная nested revoke/prune validation; дополнительных находок в этих diff не было. Это не отдельный независимый verdict всех шести фаз.

После регистрации отчёта финальные `plan.py check` — **0 errors / 0 warnings**, `plan.py render --check`, `php bin/api-manifest.php --check` и `git diff --check` — **exit 0**. Сгенерированные представления соответствуют каноническим JSON.

## Неоднозначности и состояние плана

- Прежние P6-review F1–F4 получили исправления: RefreshDatabase, MariaDB observer, dependencies и P03 trait. Для F2 доказан один короткий успешный прогон, **не** выполнено прежнее требование повторного stress acceptance.
- [D25](../../views/decisions/D25.md) фиксирует узкий тестовый baseline opt-in и физические имена PK по нативному grammar SQL engine. Изменений схемы/миграций ради косметических PK names нет. Прямой PDO rollback/restart в обход Laravel bookkeeping не поддерживается.
- I5 уточнён через plan API: адресные операции сохраняют tenant boundary; pruning по expiry/retention — предусмотренная нормативным досье операция по всем tenants.
- **P6.11 переоткрыт в todo**, исторические RED findings и результаты сохранены в плане. Следующий шаг — приёмка исправленного снимка. P1–P5 не получили фиктивную новую GREEN-квалификацию на основании частичных проверок.
- Канонические изменения плана выполняются через текущий Task CLI, представления генерируются им. Актуальная запись аудита — `knowledge/findings-P1-P6-audit-2026-10-08.json`.

## Границы результата

Не запускались полные `composer test/check`, PHPStan/type coverage всего проекта, mutation/coverage, consumer/compatibility Laravel 11/12/13 matrix, полный engine/replica stress и performance/EXPLAIN. Новые transaction/installer сценарии проверены на установленном Laravel 13; полная переносимость этого diff на 11/12 требует отдельной приёмки. Scoped checks покрывают выявленные дефекты и соседние пути, но не доказывают отсутствие всех возможных гонок.

Custom exact-query adapters сохраняют обязанность соответствовать scalar semantics. SQL write classifier — вспомогательная проверка контракта, а не анализатор всех скрытых side effects функций БД. Вычисляемые PHP config-файлы генератор автоматически не переписывает. Новый Composer solve на Illuminate-only host и запуск GitHub workflow не выполнялись.

Обнаруженные дефекты среды раскрыты: Herd PHP предупреждал о `phpstan_turbo`, поэтому финальный анализ выполнен установленным системным PHP 8.4.26; первый arch запуск с default 128M завершился OOM, финальный — с проектным 1G. Hook `security.safe-dirs` отказал удалению пустых legacy-каталогов и диагностического `/tmp/azguard-install-debug-output`; отказ не обходился, остатки не содержат рабочих DB-данных/секретов. Эти неуспешные действия не представлены как прошедшие проверки.
