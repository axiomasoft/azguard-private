# Ревью ветки и разбор реестра

Дата: 2026-11-14. Исходный срез Grok: `76644026`, база `main`: `d363abfd`.
Проверены все 31 коммит ветки, исходники, тесты и пять файлов анализа. Три независимых направления ревью:
scopes/видимость/хранилище, Filament, инфраструктура/совместимость. Исправления дополнительно проверялись между направлениями.
Этот файл уточняет исторические статусы в `problems.md`; первоначальные наблюдения сохранены.

## Что в исходном анализе было неточным

- **C1/C2:** `$value->property ?? $fallback` безопасен и при `$value === null`. Добавление `?->` не исправляло падение;
  оно создавало `nullsafe.neverNull` в PHPStan. Эти косметические изменения отменены, поведение сохранено.
- Публичная поверхность **дополнена** `ActorRef::equals()` и `bootAuthorizesPage()`; оба изменения уже присутствовали
  в манифестах ветки. Утверждение, что манифесты совсем не менялись, неверно. Совместимость проверяется компаратором.
- **P33:** retirement Redis — алгоритм операторского runbook, а не команда пакета. Самостоятельные `SCAN`/`EXPIRE`
  в drill проверяют этот алгоритм. У токенов уже был отдельный цикл обязательного принятия корректных значений;
  «парсер может отвергать всё» к текущему срезу не относится. Неиспользуемый счётчик удалён.
- **P42:** ошибка действительно унаследована, но это не основание оставить обязательную проверку красной.
  После squash #109 следует использовать SHA сквоша с теми же артефактами, сохранив происхождение кандидата.

## Исправленные подтверждённые проблемы

| Пункт | Итог ревью |
|---|---|
| C3 | Кэш состава трейтов по классу безопасен: состав загруженного класса не меняется. Сохранён. |
| C4 | Карты и каталоги редактора остаются в экземпляре компонента. Первое совпадение ключа сохранено. |
| C5 | `ArrayStore::all(false)` сохранён. Проверка повреждённых raw entries остаётся отдельной границей `mixed`; до MAC нет десериализации объектов. |
| C6 | Снята только недостижимая форма атрибута без Laravel controller middleware. Обход родителей 13.0–13.4 сохранён. |
| C7 | Discovery paths принимают сегменты PHP-идентификаторов; обход каталога и внедрение namespace отвергаются. |
| C8 | Удаление неиспользуемого внутреннего метода сохранено. |
| C9 | Общая сборка structural query сохранена, пакетная проверка дополнена сверкой владельца (P10). |
| C10 | Глобальный signing config читается один раз; диагностические результаты сохранены. |
| C11 | Матрица сужена явно до Laravel 13, MySQL 9.7, PostgreSQL 18; PHP 8.3–8.5 и lowest/latest остаются. Живые проверки указаны ниже. |
| P1 | Атрибут родителя применяется и вне panel middleware. Native router и симуляции старого поведения покрыты HTTP-тестами. |
| P3 | Таблица `ManageRelatedRecords` получает тот же visibility predicate, что relation manager. |
| P4 | Дополнительно закрыт `Page::boot()`: trait hook сам по себе выполнялся слишком поздно. Ранний Livewire hook проверяет страницу до host boot. |
| P5 | Динамический relation manager проверяется до boot и на вызовах: обязательны трейт, неизменённые методы авторизации/таблицы, mapping и доступ владельца. |
| P6 | Поддельные обновления не-editable колонки и записи не-модели дают 403. |
| P7 | Native bulk fast path допускается только без подменённых callbacks/process/lifecycle hooks. Для hooks до `ActionCalling` добавлена проверка selection до эффекта. Stock filtered delete сохранён. |
| P8 | Дополнительно исправлен `WeakMap`: запись с `null` не проходит `offsetExists()`, поэтому cold worker оставлял hint закончившейся job. Nullable hint теперь обёрнут в массив; три регрессии для поздних JobProcessed/JobExceptionOccurred/JobFailed listeners проверяют dispatch следующей job без чужой панели. |
| P9 | Путь/FQCN generated enum определяется конфигурацией, а не stdout. Чужой существующий PHP-файл из поддельного `Created` output остаётся нетронутым. |
| P10 | Batch сверяет `tenantOf()` актуальной structural model, как scalar. Владение и фильтрация проверяются в одном SQL без N+1. |
| P11 | Валидация table/short/scope закрывает инъекцию до SQL; добавлены негативные тесты. Для разрешённых lowercase identifiers дополнительные изменения DDL не нужны. |
| P12 | Tenant audit key канонизируется рядом с subject/actor. |
| P15 | Сохранены чистые role-coverage и signature caches. Signature cache переделан на **unbound** metadata: старая ReflectionFunction удерживала первый объект политики в процессе. `WeakReference` regression. Magic `__call` сохраняет сигнатуру фактически вызываемой closure. |
| P17 | Перечисление всех witnesses читает `ORDER BY id LIMIT 10001` одним SELECT; 501/10000/10001 строк проверяют порядок, полный набор и прежний отказ при превышении бюджета. |
| P21 | Кэш атрибутов сохранён. Кэш resolved middleware удалён: route middleware, exclusions и aliases могут изменяться после первого чтения. Регрессия проверяет повторный осмотр изменённого маршрута. |
| P23 | 26 scenario subprocesses объединены в 8 файлов. JUnit сравнивает точное множество выполненных имён; negative empty-filter guard сохранён. |
| P24 | Четыре повторных benchmark seeds/processes убраны. Все утверждения перенесены в общий smoke; stress contention и failure fixtures сохранены. |
| P31 | Строгое сравнение identity сохранено. Добавлена регрессия actor/subject `007` vs `7`, `10` vs `1e1`, `1e2` vs `100`. |
| P32 | Проверены поиск, отказ update/revoke и cursor на `PHP_INT_MAX`/overflow. Дополнены пропущенные bigint bindings batch grants и subject revisions; соседние ID выше 2^53 различаются. |
| P34 | Ответ владельца и связанного ресурса объединяется запретом; widget без ресурса не получает default allow. |
| P35 | Ранний guard не даёт отказанному widget выполнить boot; механизм расширен на pages и relation managers. |
| P36 | Tenant global scope регистрируется при ServingFilament каждой панели, а не только первой панели процесса. |
| P37 | Common definition filters применяются с `role/grant = null` и в SQL visibility. Contribution runtime получает role bindings/adapter. Scalar/batch/visibility parity проверен для direct grants и roles. |
| P38 | Неканонический ID query directory не приводится к соседнему integer model key. |
| P39 | Исключённый атрибут не считается покрытием strict route checks. |
| P41 | Дополнен случай policy с `before()`, но без метода ability: Laravel/Filament не вызывают этот `before`, падение в default allow закрыто. Проверен настоящий Filament helper. |
| P42 | `major_candidate` перенесён с `f70d82e` на squash `d363abfd`. Все 26 compatibility artifacts и миграции совпадают побайтово. Provenance сохранён; компаратор и migration guard не ослаблены. |

Дополнительно: обновлён Octane pin для Laravel 13; исправлен несовпадающий MySQL bootstrap socket;
пересчитан только content-hash Composer lock; устранены ошибки статических типов без suppressions и baseline.

## Что добавила реальная CI

- Lowest jobs меняют рабочий `composer.lock`. Проверка PHP locked workflows теперь читает **закоммиченный** lock,
  который эти workflows действительно устанавливают, и продолжает проверять все PHP constraints.
- Новые Filament используют `NotificationMailbox` и `CompleteExport`. Тесты поддерживают обе допустимые версии;
  содержимое denial notifications, точный export chain, авторизованный job и его context проверяются по-прежнему.
- MySQL 9.7 EXPLAIN JSON 2.0 называет выбранный индекс `index_name`. Parser дополнен этим полем; требования к индексу
  и его колонкам сохранены. Introspection CHECK может вернуть ASCII `global` с `_binary` вместо `_utf8mb4`;
  нормализуется только этот литерал, DDL fixtures и migration baseline не менялись.
- Одновременные capability subprocesses наследовали одну worker database и конфликтовали при очистке схемы.
  На server drivers дочерние проверки теперь последовательные; SQLite сохраняет четыре процесса.
- Redis runbook учитывает rootless Docker UID mapping, сохраняя каталог/socket `700`, network isolation и cap-drop.
- Composer audit обнаружил **11 advisory** в шести зафиксированных пакетах. Обновлены только Laravel `13.40.0`
  и пять Symfony-компонентов `8.1.11`; остальные версии и manifest constraints сохранены. После обновления Composer
  audit, OSV, license/plugin policies и Zizmor зелёные. Изменённые workflows проверены полностью, затем обновлены
  их review digests; политика безопасности не ослаблена.
- На точном lock выявлен баг PHPStan 2.3.4/2.3.5 с вложенным anonymous class внутри trait. Независимый минимальный
  пример подтверждает причину; writer выделен в небольшой `@internal CountedRowsWriter`. Публичная поверхность
  и contract assertions сохранены, full PHPStan проходит без ignores/baseline. У отказного `incrementOrCreate`
  уточнён смысл строкового имени колонки и проверен отказ неизвестной колонки без SQL.
- Rector 2.8 добавил новый вариант преобразования `null` checks в `instanceof`. Он отключён по уже принятому
  правилу явных nullable checks, как прежнее эквивалентное правило; массовые косметические изменения не внесены.
- При повторном использовании coverage workers полный JUnit сохранял все тесты, но Clover терял строки,
  которые существующие тесты уверенно покрывали в отдельных прогонах. Новый процесс на каждый файл
  (`--max-batch-size=1`, четыре worker) восстановил **93.6%** на тех же исходниках и прежнем пороге **85%**.
  Изоляция включена только в coverage entry points; обычная SQLite-полоса сохраняет долгоживущие workers.
- CodeQL успешно анализировал код, но новый upload-artifact отвергал старый относительный путь с `..`.
  Отчёты и SHA-bound metadata теперь хранятся и публикуются из абсолютного каталога `runner.temp`.
  Size labeler получил только нужный `pull-requests: write` в своём job, без checkout и без `pull_request_target`;
  fork PR не получает такой token. Security policy по-прежнему запрещает эту привилегию другим jobs.
- У private ownership oracle восстановлено исходное форматирование. Проверено равенство декодированных JSON-данных,
  checksum обновлён; diff против main сократился с тысяч строк форматирования до 106 добавленных строк.

Первичные advisory: [Laravel concurrency injection](https://github.com/advisories/GHSA-63hm-g96r-2m2q),
[Symfony YAML DoS](https://github.com/symfony/symfony/security/advisories/GHSA-hwfg-qwvh-2wfx).

## Решения по сложным предложениям

Здесь `сохранить` означает принятое ограничение или корректное поведение, а не непроверенную дыру.
Улучшение стоимости запроса остаётся возможной отдельной работой там, где это прямо указано.

| Пункт | Решение и причина |
|---|---|
| P2 | Сохранить явные Laravel `withoutMiddleware()` exclusions. Это конфигурационный opt-out владельца приложения. `requireRouteChecks()` уже отвергает такой unchecked route; поведение и способ строгого режима документированы. Делать strict значением по умолчанию означало бы менять контракт всех непроверяемых маршрутов. |
| P13 | Сохранить компиляцию visibility по contribution frames. Повторные owner reads и N+1 custom `resolve()` — реальная стоимость, ограниченная universe/branch budgets. Кэш между frames не доказан эквивалентным для произвольных filters/resolvers. Это возможность профильной оптимизации, не доказанный обход авторизации. |
| P14 | Сохранить scalar fallback `bounded()`: он использует одну зафиксированную `now`. У `decideMany()` нет такого входа, отличаются source snapshots и порядок ошибок. Замена без отдельного контракта неверна. |
| P15, остаток | Discovery metadata можно отдельно оптимизировать с проверкой файловых изменений. Пропуск factory `make()` и кэширование решений не допускаются. Производительность этого остатка в данном ревью не заявляется исправленной. |
| P16 | Сохранить свежие экземпляры roles/policies/evaluators. Stateful hooks могут иначе поменять следующее решение. |
| P18 | Сохранить chunks 100 scopes / 500 subjects: лимиты SQL bindings и порядок объединения являются частью корректного пакетного чтения. |
| P19 | Сохранить live catalog внутри writable mutation. Поздний шаг должен видеть permissions, вставленные ранним шагом той же операции. Наивный memo нарушает это свойство. |
| P20 | Сохранить полный dynamic catalog. Новый произвольный потолок отвергал бы допустимые панели. |
| P22 | Сохранить повторный разбор invalid config: иначе пропадает диагностическая ошибка `config.valid`. |
| P25 | Сохранить все 8 seeds и полный ledger. Частичный серверный прогон — дополнение к полному SQLite, не доказательство отсутствующих seeds. |
| P26 | Сохранить lowest qualification: она проверяет объявленный пол зависимостей. |
| P27 | Сохранить PHP 8.3 и нужную ему SQLite transaction branch. |
| P28 | Сохранить MariaDB code/local Compose. Отсутствие CI qualification уже явно отделено от наличия адаптера; удаление поддержки не было целью. |
| P29 | Сохранить panel lock. Профиль не доказал преимущество subject lock, ослаблять сериализацию оснований нет. |
| P30 | Сохранить raw context key как fallback label. Lookup без subject не обещан; новый lookup меняет отображение, фазу и стоимость. Запись остаётся валидируемой. |
| P33 | Domain-only fuzz rejections допустимы; valid token loop обязателен и сохранён. Listener reporting и panel conflict assertions уже исправлены. Partial parity не объявляется полной qualification; runbook retirement проверяется Redis drill. |
| P40 | Сохранить небольшие похожие участки: discovery и FolderSource проверяют разные контракты; общий helper не даёт доказанного выигрыша. |

## Проверки и предел доказательства

Запуски выполнялись на изолированном SQLite `:memory:` и отдельном Compose project `azguard-review`:
PostgreSQL `127.0.0.1:25432`, MySQL `:23306`, Redis `:26379`, primary/replica `:25433/:25434`.
Server lanes используют `azguard_N_test` каждого воркера; serial regression — `azguard_test`.

- Scope/Batch/Database/Storage до общей qualification: 612 passed / 18709 assertions.
- Visibility/RuntimeInputs: 201 passed / 1130 assertions.
- Redis: 28 passed / 2775 assertions. Отказ поддельного array-store объекта также проверен отдельно.
- Replica: 2 passed / 98 assertions на реальных PostgreSQL 18.7 primary/standby.
- Worker smoke: 10 HTTP lifecycles в одном PID и 10 jobs в одном PID, Laravel 13.42 / Octane 2.24 / RoadRunner 3.0.1.
  Это self-test, не memory soak. Сетевые TLS-сбои скачивания потребовали повторов; проверки не обходились.
- Composer strict validate, API manifests/compatibility, type coverage 99.9%, documentation language/version gates
  и VitePress build проверены.

Начальная локальная qualification выполнялась на старом допустимом PHP 8.3 dependency graph, который не совпадал
с закоммиченным PHP 8.4 lock. Первый parallel PCOV отчёт содержал только 2593 теста и 83% coverage; он **не принят**
как qualification. Адресные тесты подтверждают исполнение якобы непокрытых export/generator путей. Два диагностических
длинных прогона прекращены после обнаружения расхождения окружения. `composer install` на PHP 8.4 восстановил точный
lock; окончательное доказательство — новый полный прогон с JUnit и Clover, а не снижение порога 85%.

Итоговая локальная qualification на установленном lock (PHP 8.4):

| Полоса | Результат | Доказательство в workspace |
|---|---|---|
| Полный SQLite + PCOV, изоляция по файлам | **5569 passed, 425408 assertions, 0 skipped; 93.6%** (14520/15507 строк) | `build/review-coverage-isolated.log`, `-junit.xml`, `.xml` |
| PostgreSQL 18.7, canonical database lane | **949 passed, 11259 assertions** | `build/review-pgsql-locked.log` |
| MySQL 9.7.4, canonical database lane | **949 passed, 11259 assertions** | `build/review-mysql-locked.log` |
| MySQL EXPLAIN / DDL | **4 passed, 736 assertions** | `build/review-mysql-engines-locked.log` |
| Redis | **28 passed, 2775 assertions** | `build/review-redis-locked.log` |
| PostgreSQL primary/replica | **2 passed, 98 assertions** | `build/review-replica-locked.log` |
| Extended / глубокие fuzz-сценарии | **4 passed, 231804 assertions** | `build/review-extended-locked.log` |
| Очередь, включая nullable hint и поздние listeners | **11 passed, 93 assertions** | `tests/Feature/Laravel/QueueContextTest.php`, `tests/Feature/Authorization/QueueContextTest.php` |
| PHPStan max / Pint / Rector | **0 errors / passed / 0 changes** | `build/review-phpstan-final.log`, `review-pint-locked.log`, `review-rector-final.log` |

Время тестов изолированного coverage-прогона — 497.55 с без последующей агрегации отчётов. Это не утверждение
о полном ускорении относительно старого красного CI. JUnit/Clover сохранены; неуспешные диагностические прогоны
и промежуточные результаты не подменяют итоговый gate. Восемь семян паритета отдельно прошли в исходных server runs;
после исправления harness обе канонические полосы целиком зелёные. CI PR проверяет также lowest/latest матрицу.

Версии проверены на запущенных серверах: MySQL 9.7.4, PostgreSQL 18.7, Redis 7.4.11.
Исходные U1/U2/U4 больше не являются только статическими предположениями.
Первичные сведения: [Octane 2.24 manifest](https://raw.githubusercontent.com/laravel/octane/v2.24.0/composer.json),
[PostgreSQL 18.7](https://www.postgresql.org/docs/18/release-18-7.html),
[MySQL 9.7.4](https://dev.mysql.com/doc/relnotes/mysql/9.7/en/news-9-7-4.html).

Это ревью и regression qualification данной ветки. Оно не заменяет полную release-матрицу, длительный soak,
проверку production TLS/ACL и публикационные доказательства; релиз и теги в эту задачу не входят.
Локальный preflight сохранён в `build/qualification/preflight.json`: проверки среды выполнены, но общий статус не
засчитывался как зелёный из-за `unavailable` orchestration и actionlint/zizmor в обычном `PATH`. Реальные scanner/policy
запуски проверены отдельно закреплёнными инструментами; это не основание объявлять готовым production-релиз.
