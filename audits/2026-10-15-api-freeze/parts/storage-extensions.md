# Storage, writes, schema, tenancy, plugins и Audit

Аудит выполнен по `2ce692f74c210e81426e9367303701516b1cc3f0`. Production-код, штатные тесты и план не менялись. В этой части найдены четыре воспроизводимых дефекта, которые следует исправить до freeze. Все четыре имеют высокую уверенность; первые три затрагивают целостность authority или журналов, четвёртый делает допустимую декларацию grant field неработоспособной.

## Проверка и границы доказательств

Прочитаны root/package README, AGENTS.md, решения D3, D7–D10, D19, D21, D22, соответствующие разделы `knowledge/contracts.json` и владельцы работ плана `2026.10.10-№1-AZGUARD-CORE-STABILIZATION`. Решение D19 заменяет прежний сценарий adoption Audit: в оценке использованы только fresh installs. Зелёный статус плана не использован как доказательство корректности.

Просмотрены `Changes` и координатор записи, финальная валидация, защита continuation, state/revisions и транзакционный lifecycle `Storage`, public storage descriptor, схема и codec идентификаторов, `Field`/`GrantFields`/`SchemaBuilder`, tenant policy, actor/current-context isolation, декларация plugins и participants, Audit writer/schema/pruner. Смежные `Sources/Database/Internal/{GrantWriter,LockedReads,GrantRows}` прочитаны для полного пути записи. Скалярный/SQL decision pipeline и общий каталог относятся к другим частям аудита.

Воспроизведение: `php audits/2026-10-15-api-freeze/evidence/storage/reproduce.php`. Наблюдения: [observed.json](../evidence/storage/observed.json), код: [reproduce.php](../evidence/storage/reproduce.php). Среда: PHP 8.4.1, SQLite 3.43.2. Скрипт принудительно устанавливает testing, SQLite `:memory:` во всех трёх источниках env, переопределяет конфигурацию Testbench на SQLite `:memory:`, проверяет фактические подключения перед записью и закрывает их в конце. Оба подключения находятся только в памяти. Fixture подготавливает модель CRM; изменения grant во всех сценариях вызываются через public `AzGuard::panel(...)->inTenant(...)->for(...)->grantRole(...)`. Prune запускается настоящей публичной командой через Console Kernel. Создание тестовых таблиц и проверка содержимого используют внутренние fixtures.

Перед воспроизведениями root обнаружил stale Composer PSR-4 для извлечённых модулей и восстановил локальное vendor metadata. До подтверждения восстановления проверки не запускались. Общую SQLite/server qualification выполняет root; эта часть не заявляет отдельный полный прогон и не переносит SQLite-наблюдения на другие драйверы автоматически.

## ST-01. Participant обходит запрет записи в authority table через регистр имени

**Severity: Major. Confidence: high. Обязательное исправление перед freeze.**

**Локации:** `packages/core/src/Panels/Internal/PanelCompiler.php:252`, `:257`, `:261`; `packages/core/src/Changes/Internal/OperationRows.php:35`, `:60`; `packages/core/src/Storage/Internal/Storage.php:149`.

**Контракт и достижимость.** D8 и `Contracts/Changes/OwnedRowsWriter` обещают append-only capability для собственных таблиц участника. `tables()` проверяется при компиляции; шесть core tables запрещены. Участник регистрируется обычным `$builder->participants([$participant])` или plugin, после эффективного public grant получает `OwnedRowsWriter`.

**Триггер.** Участник объявляет `tables(): array { return ['ROLE_GRANTS']; }` и вызывает `$rows->insert('ROLE_GRANTS', [$row])`. Compiler проверяет только non-empty string, затем сравнивает с lower-case core names через строгое `in_array`. `OperationRows` сравнивает имя с тем же разрешённым списком и отправляет его в SQL с storage prefix. SQLite считает `azg_ROLE_GRANTS` и `azg_role_grants` одной таблицей.

**Подтверждённое последствие.** Один обычный grant субъекту 2 запустил участника. Тот вставил дополнительный grant субъекту 1. Public operation вернула один effect — только законного изменения; revision субъекта 1 осталась отсутствующей (`null`, то есть baseline revision 0). После нового чтения субъект 1 получил доступ: `access_before=false`, `access_after_new_read=true`. Дополнительная запись не проходила `ChangeValidator` и не попала в список effects/обычных событий этого изменения. На путях с кэшированием неверная revision дополнительно нарушает договор обновления grants.

**Граница риска.** Для этого требуется зарегистрированное доверенное PHP-расширение: это не утверждение об удалённой атаке посетителя. Дефект состоит в несоблюдении публичного ограничения capability и защите от ошибочного расширения. Рабочий обход доказан на SQLite. На MySQL/MariaDB его достижимость зависит от режима сравнения table names; PostgreSQL с quoted identifiers не следует считать уязвимым к этому конкретному варианту без отдельной проверки. Та же case-sensitive таблица `$owners` не обнаружит двух участников, претендующих на один physical table под разным регистром на case-insensitive драйвере.

**Исправление.** Зафиксировать единственную grammar имени participant table на границе компиляции, например lower-case ASCII base identifier без schema/alias/точек/пробелов/кавычек. Проверять core ownership и уникальность после одной нормализации. Предпочтительнее отвергать неканоническое имя явным `DefinitionException`, чем бесшумно переписывать его. `OperationRows` должен принимать именно скомпилированный identifier; не нужен general SQL или sandbox API.

**Acceptance tests.** Каждый core table в lower/upper/mixed case отвергается до записи; qualified/aliased/malformed names отвергаются. Два participant с case-вариантами одного имени не получают две capabilities. Допустимая сторонняя таблица по-прежнему пишет на connection/prefix операции; rollback/retry и закрытие writer сохраняются. Регрессия из evidence должна завершаться отказом компиляции, без дополнительного grant, effects, событий и revisions.

**Связь с планом.** `2026.10.10-№1-AZGUARD-CORE-STABILIZATION P2.11` — transactional participants и ограниченный writer, D8; последующие `P3.4`, `P3.8` — relocation соответствующих владельцев. Это пробел реализации контрактного ограничения, а не предложение расширить SPI.

## ST-02. StorageDescriptor теряет выбранное physical connection при смене default DB

**Severity: Major. Confidence: high. Обязательное исправление перед freeze.**

**Локации:** `packages/core/src/Storage/Internal/StorageRegistry.php:25`, `:26`, `:69`, `:75`; `packages/core/modules/audit/src/Internal/JournalPruner.php:43`; также `packages/core/modules/audit/src/Internal/AuditSchema.php:105` и `packages/core/src/Storage/Schema/IdentityColumns.php:74`.

**Контракт и достижимость.** Public `AzGuard::storage()` и `OwnedRowsWriter::storage()` возвращают описание storage, включая connection. В `StorageRegistry` authority storage привязывается к конкретному `Connection` при создании, но descriptor содержит исходное конфигурационное `null` — «default». Модули используют это значение в `DatabaseManager::connection()` повторно.

**Триггер.** Приложение настроило AzGuard storage с connection `null`, уже разрешило его на центральную БД, затем multi-tenant middleware/job поменял Laravel default connection. Authority writer продолжает работать на старом `Connection`; audit migration/doctor/prune повторно разрешают `null` на новый default. Для воспроизведения достаточно `DB::setDefaultConnection('secondary')` после инициализации storage и запуска `azguard:audit:prune --panel=crm --force`.

**Подтверждённое последствие.** У core storage `connectionName() = testbench`, descriptor сообщает `connection = null`, текущий default — `secondary`. Команда завершилась exit 0 и сообщила об удалении одной строки. Реальный журнал authority storage сохранил свою старую строку; строка в одноимённой таблице другой БД была удалена. Если таблицы на новом default нет, вместо неверного удаления произойдёт ошибка. Возможна также DDL/health работа не с тем storage, поскольку они используют тот же descriptor.

**Граница риска.** Нужна смена default connection после разрешения storage. Это конкретный сценарий Laravel-приложения с центральной authority DB и динамическими tenant connections; при неизменном default рассогласования нет. В evidence обе БД — отдельные SQLite memory connections; реальные внешние данные не затрагивались. Reconnect/purge под тем же именем здесь не исследовался и в доказательство не включён.

**Исправление.** Descriptor операции должен передавать разрешённое имя connection, согласованное с `Storage::connectionName()`. Если продукту действительно нужны одновременно исходная конфигурация и рабочий descriptor, это должны быть два явно разных поля/типа с различным смыслом. Нельзя использовать `null` как отложенное разрешение физического storage для операций с его таблицами. Проверить также private storages из `StorageRegistry::own()`.

**Acceptance tests.** Разрешить default и private storage на connection A, сменить default на B; descriptor, Audit migration/doctor/prune и schema helpers должны продолжать работать с A или явно отвергнуть устаревшее описание до I/O. Журнал B не меняется. Named connections и обычный неизменный default сохраняют поведение. Fixture с двумя SQLite memory DB должен проверять содержимое обеих БД, а не только exit code.

**Связь с планом.** `2026.10.10-№1-AZGUARD-CORE-STABILIZATION P2.3` — public storage descriptor; `P3.5` — независимая Audit schema; `P3.6` — Audit retention без authority lock; `P4.4` — независимая relocation Audit. D3/D7 требуют одного корректного владельца persistence. Извлечение модуля сделало descriptor фактической границей connection identity, и именно этот контракт нужно завершить до freeze.

## ST-03. Public AuditOptions позволяет обойти retention validation и удалить свежий журнал

**Severity: Major. Confidence: high. Обязательное исправление перед freeze.**

**Локации:** `packages/core/modules/audit/src/AuditOptions.php:15`; `packages/core/src/Panels/Internal/PanelCompiler.php:204`; `packages/core/modules/audit/src/Internal/Console/AuditPruneCommand.php:43`, `:60`; для сравнения `packages/core/modules/audit/src/AuditPlugin.php:61` валидирует дни в factory/fluent API.

**Контракт и достижимость.** D8 ввёл typed readonly module options и осмысленное разрешение слоёв. Provider может переопределить options плагина через public `PanelBuilder::options()`. `AuditOptions` опубликован с public constructor. Сам `AuditPlugin::make()`/`retention()` требует минимум один день, но этот инвариант находится только в plugin wrapper.

**Триггер.** `$builder->plugins([AuditPlugin::make()])->options(new AuditOptions(-10))` успешно компилируется: readonly тип корректен, provider options выигрывает у plugin options. Затем обычный `azguard:audit:prune` берёт отрицательное число и вызывает `$now->modify('--10 days')`.

**Подтверждённое последствие.** На часах fixture `2026-10-06T12:00:00Z` только что записанная строка была удалена. Команда использовала cutoff `2026-10-16T12:00:00+00:00`, то есть будущее, и вернула exit 0. `fresh_rows_before=1`, `fresh_rows_after=0`. Документированное свойство prune «не идти дальше retention» не спасает, когда сама retention невалидна.

**Граница риска.** Это ошибочная конфигурация доверенного приложения/плагина, не пользовательский HTTP input. Вес находки определяется необратимым удалением audit rows и тем, что путь использует опубликованный constructor, а не reflection или внутренний mutation API.

**Исправление.** Перенести инвариант `retentionDays >= 1` в сам `AuditOptions` и использовать его из plugin factory/fluent методов, сохранив один источник проверки. Defensive проверка непосредственно перед destructive prune полезна как последний барьер, но не заменяет невозможность создать невалидное options value. Ошибка должна возникать до destructive I/O.

**Acceptance tests.** `new AuditOptions(0)` и отрицательные значения отвергаются; override через provider/configure не обходит проверку. Retention 1 и 90 работают. Строка с `occurred_at == cutoff` сохраняется; свежая строка сохраняется; старые строки удаляются. В случае некорректной конфигурации exit code сообщает отказ, обе проверяемые таблицы остаются неизменными.

**Связь с планом.** `2026.10.10-№1-AZGUARD-CORE-STABILIZATION P2.5` — typed module options, `P2.11` — AuditOptions/participant, `P3.6` — retention command, D8. Необходима проверка значений в их владельце, а не новая межмодульная validation infrastructure.

## ST-04. Field::multiple() в собственной JSON-колонке проходит декларацию, но не записывается

**Severity: Major. Confidence: high. Исправить или явно сузить контракт до freeze.**

**Локации:** `packages/core/src/Storage/Internal/GrantFields.php:100`, `:105`, `:138`, `:152`; `packages/core/src/Sources/Database/Internal/GrantWriter.php:135`, `:139`, `:197`; public декларация `packages/core/src/Schema/Field.php:95`.

**Контракт и достижимость.** Public `Field` описывает custom column или meta value; `multiple()` и `inMeta()` — независимые настройки. `PanelBuilder::fields()` и модельный `azguardFields()` принимают такую декларацию. Core schema и штатный Filament renderer признают её многозначной: `packages/filament/src/Forms/SchemaFields.php:63` создаёт list input. Compile-time запрета multiple column нет.

**Триггер.** Приложение добавляет JSON-колонку `tags` в grant table, объявляет `Field::string('tags')->multiple()` и передаёт `fields: ['tags' => ['finance', 'sales']]` в public `grantRole()`. Это естественный сценарий собственной индексируемой JSON-колонки вместо общего `meta`.

**Подтверждённое последствие.** Компиляция успешна, field validation проходит. `GrantFields::toRow()` возвращает массив в `columns.tags`; `GrantWriter` передаёт его напрямую query builder, кодирующим только весь `meta`. Результат — `Illuminate\Database\QueryException: Array to string conversion`. Изменение grant откатывается. Обратный путь также требует решения: `stored()` берёт raw attribute колонки и не декодирует JSON-массив. Поэтому исправить только insert недостаточно для roundtrip/idempotency.

**Исправление.** Принять одно явно выраженное решение для Field persistence. Рекомендуемый вариант — единый codec полей для scalar/list → SQL representation и обратного преобразования, одинаковый для insert, replace, чтения records, fingerprint и decision values; list column хранит JSON, list meta остаётся массивом внутри JSON meta. Альтернативный меньший контракт — разрешать `multiple()` только в `meta` и отказывать при компиляции с понятной ошибкой. Такой сознательный отказ предпочтительнее обещанного UI-поля, которое падает при submit. До freeze выбрать и документировать одно поведение. Подключение Eloquent `save()` ради casts здесь не требуется: оно изменило бы существующую границу единственного writer.

**Acceptance tests.** JSON column с list<string>, list<int> и backed enum проходит create/read/update; тот же normalized list возвращает `Unchanged` без events/revision bump; изменение списка возвращает `Applied`. Покрыть null, empty list, optional field, invalid element, column и meta, пользовательский grant model и обычное panel field. Проверить roundtrip на SQLite/MySQL/MariaDB/PostgreSQL. Если выбран запрет multiple column — все такие декларации отвергаются до первой операции и UI schema их не публикует.

**Связь с планом.** Не утверждается, что дефект внесён последним планом: custom fields существовали до него. Актуальные владельцы проверки — `2026.10.10-№1-AZGUARD-CORE-STABILIZATION P3.9` (storage/testing relocation), `P4.2` (qualification sweep) и `P4.7` (точность frozen contracts). Перед freeze надо зафиксировать реально поддерживаемую композицию уже опубликованных методов.

## Дополнительные решения API перед freeze

Это рекомендации по уже существующей поверхности, а не дополнительные подтверждённые аварии и не требование общей перестройки.

1. **Привести dynamic-permission API к одному value object.** `PermissionManager::create()` (`packages/core/src/Contracts/Changes/PermissionManager.php:38`) принимает label/group/fields, но не description; `update()` принимает `PermissionDetails`. При этом `ChangeValidator::actionFields()` (`packages/core/src/Changes/Internal/ChangeValidator.php:317`) отвергает любое непустое поле dynamic permission. Потребитель, создающий permission с описанием, вынужден делать create+update; параметр fields предлагает запрещённую возможность. До freeze разумно унифицировать create/update на `PermissionDetails` и удалить либо прямо ограничить неработающий fields surface. Не добавлять custom permission storage schema без отдельной потребности.

2. **Сделать FieldSchema машинным описанием поля.** Сейчас cardinality кодируется строкой `'array'` в `rules`, а model class — внутри строки `'exists:Class,key'` (`packages/core/src/Schema/FieldSchema.php:22`, `:43`, `:48`). Уже штатный Filament читает структуру поля через поиск строки (`packages/filament/src/Forms/SchemaFields.php:63`). Внешнему editor/schema consumer нужны явные `multiple`, `required`, `valueClass`/model identity; validation rules могут оставаться отдельной проекцией. При исправлении ST-04 лучше завершить эту семантику до закрепления constructor и JSON shape.

3. **Сохранить текущие узкие границы.** D10 осознанно оставляет authority writes ядру; подтверждённые проблемы не требуют возвращать generic writer SPI. D8 declaration-only plugin и append-only transactional participant подходят для ledger/outbox consumer. Нужны точные идентификаторы, валидные options и codec, а не общий bus, произвольная замена стадий или новый lifecycle. `docs/advanced/consistency.md` всё ещё рекомендует `Storage::mutate()` как путь для пользователя, хотя storage теперь internal, а public `AzGuard::storage()` возвращает descriptor. Убрать эту рекомендацию либо явно адресовать её внутреннему коду; не публиковать весь mutation API ради устаревшего текста.

## Что сохраняет архитектурную ценность

В просмотренном коде финальная проверка identity/partition выполняется после pipes; continuation обязан быть вызван ровно один раз и вернуть собственный результат writer. Participant запускается после effects внутри транзакции и получает закрываемый writer; события строятся один раз и откладываются до commit. Внешняя транзакция отражается в `ChangeResult::committed`; авторизация tentative authority привязана к root transaction, PDO и Fiber. Actor и current context отделены по Fiber. Audit retention имеет отдельные короткие batch-транзакции и не поднимает authority revisions. Эти механизмы стоит сохранить при адресных исправлениях. В данной части не найдено оснований для полного переписывания write pipeline или новой абстракции tenancy.

Для исполнения другой моделью достаточно четырёх независимых изменений: canonical participant table identity; descriptor с разрешённым connection; инварианты AuditOptions; завершённая field persistence semantics. После каждого — адресные проверки инвариантов выше, затем общая квалификация и штатная регенерация API manifests/format fixtures через их владельцев. Сам аудит не вносит эти изменения.
