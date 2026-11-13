# Реестр проблем

Ветка `chore/latest-platform-and-faster-runs`, снимок 2026-11-13. Это полный список того, что нашли прогоны агентов и разбор матрицы. Закрытое не вычёркивается: у каждой строки есть статус. Детали прогонов CI лежат в [01-run-shape.md](01-run-shape.md), отложенные продуктовые идеи ещё и в [02-proposals.md](02-proposals.md), сделанные оптимизации в [03-code.md](03-code.md), ход двадцати агентов в [04-agent-sweep.md](04-agent-sweep.md).

Статусы: `closed` уже в коммите этой ветки; `open` ещё в коде; `held` проверено и трогать нельзя без смены доказательства или решения; `unverified` живьём не гонялось.

Публичная поверхность против `main`: методы, ключи конфигурации, типы исключений и оба манифеста не менялись. Сужен пол `illuminate/*` до `^13.0` и обещание серверов до MySQL 9.7 и PostgreSQL 18. 2.0 ещё без тега.

## Закрыто

| ID | Было | Где закрыто |
|---|---|---|
| C1 | `#[CheckPermission]` без атрибута читал `->message` у null и падал `Error` до `??`. Отказ теперь идёт в перевод и 403. | `CheckPermission.php`, nullsafe |
| C2 | Пустой `describe()` субъекта или контекста гранта падал до `?? $key`. | `GrantEditor.php` |
| C3 | `class_uses_recursive()` на каждом Livewire mount/hydrate, проверке ресурса и soft-deletes. | `ClassTraits` |
| C4 | Строка таблицы грантов заново обходила роли, права, модели субъектов и собирала `directories()`. | карты и кэш каталогов в `GrantEditor` |
| C5 | Array-store читался рефлексией приватного `storage`, потому что в Laravel 12.0 не было `all(false)`. На 13.39 метод есть и с `false` не вызывает `unserialize`. | `PermissionSetCache::arrayItem` |
| C6 | Вторая форма класса `CheckPermission` для Laravel без атрибутов контроллера. На 13.0–13.39 `Middleware` есть всегда, ветка не загружалась. | атрибут оставлен одним классом |
| C7 | `azguard.discovery.*` проверялся только на NUL. `../tmp` и `Roles;Exit` становились путём и куском namespace, генератор делал `require_once`. | сегменты-идентификаторы в `AzGuardConfig` и `ScaffoldConfig`. `Abilities/Perms` жив. Тест в `ConfigSectionsTest` |
| C8 | `MakeCommand::file()` никто не вызывал. | метод удалён |
| C9 | `EligibilityBuilder::matchesMany` копировал сборку структурного запроса вручную. | вызов `structural()`, SQL тот же |
| C10 | Doctor вызывал `CacheSigningConfig::fromConfig()` на каждую Redis-панель. Материал подписи глобальный, находки те же. | один вызов за прогон в `CacheIntegrity` |
| C11 | Матрица CI держала Laravel 12, MariaDB, MySQL 8 и PostgreSQL 16 и гоняла SQLite последовательно под Xdebug. | Laravel 13, MySQL 9.7.4, PostgreSQL 18.7, MariaDB только в локальном Compose, 4 процесса Pest, coverage на PCOV |

## Открытые дефекты

Их не чинили, потому что правка меняет ответ, строки таблицы или SQL. Ниже текст агента, не сокращение.

### Авторизация HTTP

`P1` medium, closed. `EnterPanel::outside()` на `RouteMatched` добавляет middleware родительского атрибута, если маршрут не входит в панель. На 13.5+ `missing()` пуст, метод ничего не делает. `RouteChecksTest` зелёный. Было: `P1` medium, open. `packages/core/src/Laravel/Internal/Http/Middleware/EnterPanel.php:108`. На Laravel 13.0–13.4 маршрутизатор не собирает `#[CheckPermission]` с родительского контроллера. `RouteChecks::missing()` применяется только после допуска `azguard.panel`. Маршрут, который в панель не входит, выполняет действие, а родительский атрибут игнорируется. Doctor сообщает и запрос не останавливает. Починка: отказ, если у собранного маршрута всё ещё нет middleware атрибута, в том числе вне панели.

`P2` medium, open. `packages/core/src/Laravel/Internal/Http/RouteChecks.php:86`. `missing()` отбрасывает `#[CheckPermission]`, чей middleware исключён. Strict mode выключен, пока панель не вызывает `requireRouteChecks()` (`EnterPanel.php:104`). Допущенный субъект доходит до действия. Тест исключения логинит пользователя 2 и получает HTTP 200 на update, который атрибут запретил бы. Починка: считать исключённую или отсутствующую проверку атрибута отказом даже без strict mode, либо сделать strict mode значением по умолчанию.

Родительский обход в `RouteChecks.php:50`, применение в `EnterPanel.php:108` и отчёт Doctor в `RoutesChecks.php:90` на 13.0–13.4 ещё нужны. В `illuminate/routing` тега `v13.0.0` и `v13.4.0` нет прохода по `getParentClass`. Он появляется в `v13.5.0` и есть в локальном 13.39. Удалять нельзя, пока пол пакета `^13.0`.

### Filament

`P3` high, closed. На mount и hydrate страницы `ManageRelatedRecords` таблица получает тот же `FilamentGate::visible()`, что relation manager. `RelatedRecordsPageTest` прячет секретный товар 2 и показывает товар 1. Было: `P3` high, open. `packages/filament/src/Internal/Authorization/FilamentContext.php:161` и `packages/filament/src/Concerns/AuthorizesRelationManager.php:40`. `ManageRelatedRecords` авторизуется как связанный ресурс, но `FilamentGate::visible()` вешает только relation manager. Сценарий: у заказа 1 видны товар 1 и секретный товар 2; у пользователя есть `orders.view`, `products.view_any` и `products.view`. `ProductsRelationManager` показывает одну строку. `ManageOrderProducts` показывает товар 2, и его можно отцепить. Починка: тот же `visible()` на таблице страницы связанных записей.

`P4` medium, closed. `AuthorizesPage::bootAuthorizesPage()` делает `abort_unless(canAccess(), 403)` в boot, до `mount()`. `PanelsAndDoctorPagesTest` зелёный: без права страница 403, с правом doctor открывается.

`P5` medium, closed для mount/hydrate/call. `RefusesSurfaceCalls` обрывает enforced relation manager без `AuthorizesRelationManager` на mount, hydrate и call. Менеджер, который страница добавляет в рантайме, больше не живёт без трейта. `P5` medium, было open. `packages/filament/src/AzGuardPlugin.php:531` и `:598`, `RefusesSurfaceCalls.php:19`. Enforced-панель отказывает виджету без `AuthorizesWidget` на Livewire `mount` и `hydrate`, потому что `getHeaderWidgets()` на boot неизвестен. Relation manager проверяются только пока boot читает `Resource::getRelations()`. `RefusesSurfaceCalls` повторно режет страницы и виджеты на `call` и не смотрит `RelationManager`. Сценарий: `getRelationManagers()` страницы редактирования добавляет менеджер без трейта, у связанной модели политика разрешает `viewAny`. Менеджер монтируется, таблица не сужается видимостью, поздние вызовы не обрываются. Тот же пропуск у виджета даёт 403. Починка: тот же сторож mount/hydrate и повтор на `call` для relation manager.

`P6` medium, closed. `RefusesEditableColumns` больше не возвращает управление для колонки не `Editable` и для записи не-модели: оба пути `abort(403)`. Выключенная editable-колонка по-прежнему доходит до Livewire и не пишет. `SurfacesTest` и `ForgedCallsTest` зелёные.

`P7` medium, closed. Ранний выход bulk-delete остаётся только если callback всё ещё метод `DeleteBulkAction`, `ForceDeleteBulkAction` или `RestoreBulkAction` и `using()` пуст. Подменённый `action()` или `using()` проходит проверку явных ключей. Штатный `V96` по-прежнему удаляет видимые строки и шлёт уведомление. `SurfacesTest` зелёный.

### Очередь

`P8` medium, closed. `PanelContext::snapshot()` слушает `JobProcessing` до hydrate и запоминает скрытый hint. `leaving()` пишет его обратно: у sync-запроса остаётся `crm`, у воркера без hint ключ снимается. `QueueContextTest` зелёный. Было: `P8` medium, open. `packages/core/src/Laravel/Internal/Queue/PanelContext.php:100`. `leaving()` восстанавливает `CurrentPanel` и оставляет скрытый контекст `azguard.panel`. Job, отправленный из более позднего слушателя `JobProcessed` или `JobAttempted` (провайдеры приложения идут после этого), встаёт в очередь с id панели уже закончившейся работы, и `entering()` ставит её панелью для коротких имён. Починка: снять этот скрытый id в слушателе `JobProcessing`, который выполняется до `Context::hydrate` Laravel, и записать снимок обратно в `leaving()`.

Удалённый id панели по-прежнему отвергается, а не подменяется панелью по умолчанию. Секретов по умолчанию в `config/azguard.php` нет. `InstallCommand.php:227` запускает `php artisan` через `Process` списком аргументов: `migrate` и `azguard:doctor`, `--no-interaction`, необязательный `--force` и `--env` родителя. Соединение только переменная окружения и должно уже быть ключом `database.connections`.

### Генераторы, хвост после C7

`P9` medium, closed частично. После regex путь проходит `realpath` и должен лежать внутри `base_path()`, `..` в относительном куске отвергается до записи и `require_once`. Парсинг stdout остаётся: если регекс поймал чужой существующий файл внутри приложения, он всё ещё может быть перезаписан. Это уже не выход из каталога приложения.

`Place::in()` (`packages/core/modules/devtools/src/Internal/Scaffold/Place.php:23`) по-прежнему склеивает `$relative` в каталог и в namespace. После C7 discovery туда уже не пускает `..` и точку с запятой. Обход через сам `azguard.scaffold.path` по-прежнему закрыт отдельно: `ScaffoldConfig` отвергает `..`, абсолютный путь и схемы URL.

### Scopes

`P10` high, closed. `matchesMany()` после union перечитывает строку тем же `structural()` и требует `tenantOf($record)->equals($resolved->tenant)`, как `matches()`. Пакет больше не разрешает свидетеля, которого одиночная проверка отвергает из-за владельца. Модульные тесты scopes зелёные.

`QueryScopeDirectory.php:111` поиск не читает назначения без границы: страницы идут `limit($chunk)` (по умолчанию 200) и останавливаются на `maxScan` (по умолчанию 10000). Проверка тенанта на строке 116 в PHP, потому что `tenantOf()` произвольный callback (строки 41–44). Чужие тенанты могут сжечь бюджет сканирования, метод бросает `DirectoryScanLimitException`, а не возвращает чужой тенант. Заталкивание колонки тенанта в SQL сломает владельцев с callback. Не дефект.

### Хранилище и журнал

`P11` low, partly closed. `scopeConstraint()` отвергает table, short и scope, если это не `[a-z][a-z0-9_]*`, до склейки SQL. Константы `audit_log` / `al` / `tenant` по-прежнему проходят и дают тот же DDL. Кавычки внутри предиката не менялись, чтобы не разойтись со снимками схемы. Было: `P11` low, open. `packages/core/src/Storage/Schema/IdentityColumns.php:61`. `scopeConstraint()` подставляет `$scope`, `$short` и выведенные имена колонок в сырой DDL. Имя таблицы и ограничения обёрнуты, предикат `CHECK` нет. На SQLite не обёрнуты список `UPDATE OF` и литерал `RAISE`. `AuditSchema` передаёт только константы `audit_log`, `al` и `tenant`, из запроса это сегодня не достать. Вызывающий, который прокинет настроенные scope или short (публичный SPI принимает эти строки), вырывается из оператора. Починка: принимать у `$table`, `$short` и `$scope` только `[a-z][a-z0-9_]*`, кавычить каждую колонку и собирать сообщение SQLite кавыченным литералом.

`P12` low, closed. `JournalRow::stored()` прогоняет `canonicalKey()` и для `tenant_id`, рядом с subject и actor. `null` по-прежнему пропускается. `AuditPluginTest` зелёный.

`P31` high, closed. Сравнение актора, субъекта, роли и права шло через `==` / `!=`. Для строк PHP считает `'007' == '7'`, `'10' == '1e1'`, `'1e2' == '100'`. Из-за этого `Change::sameIdentity` принимал подмену актора, `ChangeValidator::actorModel` отдавал модель другого человека, а `GrantWriter::update` не бросал `StaleSelectionException` и писал в чужую строку. Теперь актор сравнивается `ActorRef::equals()` (строгое `===` по полям), субъект, роль и право — через `equals()`. `tests/Feature/Changes` и `tests/Feature/Storage`: 532 passed.

`P34` high, closed. `AuthorizesActions` больше не берёт первый непустой ответ `otherwise ?? owner`. `CreateAction` и `ReplicateAction` на записи владельца требуют `update` владельца и ответ связанного ресурса одновременно. У виджета без ресурса пустой ответ по умолчанию это deny, не allow. Сторож виджетов без трейта регистрируется из `AzGuardFilamentServiceProvider::boot()`, а не из boot первой панели процесса. Gate-тесты: 148 passed. Два теста `DenialDisclosureTest` падают на отсутствующий `Filament\Notifications\NotificationMailbox` в этом окружении, на строке flush, не на решении.

`P35` medium, closed. `RefusesWidgetBoot` регистрируется до хука жизненного цикла Livewire. Отказ происходит до `boot()` класса. `ResourceWidgetsTest` проверяет, что запрещённый виджет не увеличивает счётчик boot, а исключённый виджет увеличивает.

`P36` medium, open. Глобальный scope тенанта Filament вешается из `Panel::boot()`. `bootCurrentPanel()` поднимает одну панель на процесс. Если первым загрузилась панель без плагина, enforced-панель этого процесса не регистрирует scope, и запросы ресурса не режутся тенантом.

`P37` open. Попытка гонять фильтры определения на прямом гранте удвоила вызов: `RuntimeInputsTest` требует один проход с `grant === null`. `decide()` и `visibleTo()` по-прежнему могут разойтись, если фильтр смотрит на `grant`. Это решение продукта, не тихая правка.

`P41` high, closed. `FilamentGate::before()` возвращал null, если у модели была любая политика, даже без метода этой способности. Filament тогда разрешал проверку. Теперь null только если способность зарегистрирована в Gate или политика объявляет `before` либо сам метод. Иначе false. Действие enforced-ресурса больше не обходит `FilamentGate::resource()`, когда ответ Filament по умолчанию непустой: запрет любого из двух побеждает. `ResourcesTest` фиксирует политику только с `delete`.

`P40` held. Одинаковые на вид куски не склеены, потому что общее тело меняет проверку. `FolderSource::pairedEnum` не требует string-backed enum, `PanelDiscovery::policyFor` требует и пишет другое сообщение. `PermissionsGrantCommand` передаёт в `panelFor` права, `RolesGrantCommand` передаёт роли. Общий `handle()` легко подставит не тот каталог.

`P38` closed. `QueryScopeDirectory::match()` сравнивает ключ только если `ModelKey::canHold()`. `05` больше не ищется как id 5.

`P39` closed. `covered()` возвращает false, если middleware атрибута исключён и `resolveMiddleware` даёт пустой список. `can:view` больше не делает strict mode «покрытым». Нестрогий тест с `assertOk()` на исключённой проверке не читает `covered()` и остаётся зелёным. `RouteChecksTest` зелёный.

`P33` partly closed. Грамматический fuzz теперь требует, чтобы `user` и `1` канонизировались. Слушатель `AccessDecided` должен сообщить исключение и сохранить то же решение. Три пустых `catch (ConflictingPanelException)` заменены на `toThrow`. Остаётся:

- `tests/Unit/Fuzz/GrammarFuzzTest.php` по-прежнему считает отказ alias на случайной строке успехом: грамматика alias уже, чем грамматика id. Известный alias `user` теперь обязателен.
- `tests/Unit/Fuzz/StateTokenFuzzTest.php` считает отказ повреждённой строки успехом. Неповреждённые токены проверяются отдельным циклом.
- `tests/Feature/Console/ProductionRunbookTest.php:255` на не-Redis только проверяет `ArrayStore` и выходит. Команда retirement там не выполняется. Это сознательная граница локального прогона.
- `tests/Feature/Parity/ParityMatrixTest.php:50` при неполном `PARITY_SEEDS` сравнивает замороженный ledger с самим собой. Иначе частичный прогон ломал бы полный снимок.
- `tests/Unit/Fuzz/StateTokenFuzzTest.php:41` делает `return` до round-trip, `$accepted` нигде не проверяется. Парсер, который отвергает все сгенерированные строки, всё равно зелёный.
- `tests/Feature/Events/AccessDecidedTest.php:93` «reports a failing listener» проверяет только `toBeInstanceOf(Effect::class)`. Любой `Decision` это удовлетворяет.
- `tests/Feature/Console/ProductionRunbookTest.php:255` после `toBeInstanceOf(ArrayStore::class)` возвращается, если кэш не Redis. Команда retirement не выполняется. На Redis тест сам вызывает `$redis->expire()`, сломанная команда пакета всё равно зелёная.
- `tests/Feature/Parity/ParityMatrixTest.php:50` при заданном `PARITY_SEEDS` делает `$stored->toBe($stored)`. Неверный ledger unsupported не падает.
- `tests/Acceptance/Crm/SubjectApiTest.php:81` и `:171`, а также `tests/Feature/Concerns/PublicWrapperTest.php:76`: `catch (ConflictingPanelException) {}` выбрасывает сам факт отказа. Если `hasPermission('backoffice:…')` возвращает значение вместо исключения, дальнейшие проверки `[true, false, true]` всё равно проходят.

`P32` partly closed. `GrantWriter::number`, `ActionWriter::number` и курсор `ScopedGrantManager::decode` отвергают цифровую строку, которая не равна `(string) (int)`. Id выше `PHP_INT_MAX` больше не пишется и не листается как соседний int. `GrantInspection::find` и `LockedReads::find` тоже возвращают null и не подставляют строку в `where('id')`, если она не равна `(string) (int)`. Было: `P32` open. `ScopedGrantManager.php:124` приводит курсор `(int) $parts[2]`. Регекс пускает 19 цифр. `9223372036854775808` становится `9223372036854775807`, и следующая страница берёт `id >` этого потолка. Тот же каст в `GrantWriter.php:252` и `ActionWriter.php:115`: id гранта выше `PHP_INT_MAX` обновляет или удаляет другую строку. `GrantInspection.php:58` и `LockedReads.php:230` передают цифровую строку в `where('id')` без проверки диапазона bigint: Postgres роняет транзакцию, MySQL может сматчить другой id выше 2^53. Не чинилось в этом заходе: правка курсора меняет границу страницы, её надо закрывать отдельным тестом на границе int.

Prune журнала дефектом не признан. `JournalPruner::batch` (`JournalPruner.php:62`) берёт не больше 1000 id с `panel = ? AND occurred_at < ?` и удаляет эти первичные ключи. Фильтр панели на select, id уникальны, строки журнала не обновляются, чужая панель остаётся. `--before` только сдвигает границу раньше (`AuditPruneCommand.php:60`). `JournalRow::COLUMNS` совпадает с колонками `audit_log`, которые пишут и проверяют в `AuditSchema`.

### Видимость и решения

`P13` open, стоимость и форма SQL. `Visibility::build()` собирает вселенную контекстов один раз для внешней группы и снова для каждого вклада. Внешний вызов `mapping->query($request, $frame, true)` в `Visibility.php:117`. Каждый выживший грант или роль вызывает `mapping->query($request, $branchFrame)` в `Visibility.php:179`. Кадр ветки несёт другую роль, грант и иногда контекст, поэтому мемо на `AccessRequest` не попадает. Без владельца `FiltersAccessQueries` метод `ResourceContextMapping::owner()` (`ResourceContextMapping.php:211`) каждый раз делает свежий `limit(1001)->get()`. Когда `resolve()` не равен базовому `query()` плюс `tenantOf()` (`resolvesThroughQuery` ложен), цикл в `ResourceContextMapping.php:226` вызывает `resolve()` на каждую строку контекста, а `BaseAssignmentScope::resolve()` (`BaseAssignmentScope.php:64`) делает `ModelKey::find()`. Это один запрос на строку и на ветку. Штатный базовый путь остаётся в памяти через `tenantOf()`, но чтение 1001 строки повторяется. Если evaluator отказывает глобальному рантайму, `perContext()` (`ResourceContextMapping.php:169`) снова делает `get()` того же набора и заново строит предикат допуска на каждый контекст. Один глобальный список с фильтрами компилируется так: чтение владельца, второе чтение, затем оба снова на каждое плечо. Итоговый SQL встраивает этот предикат и во внешнюю группу, и внутрь каждого `OR`.

`P14` open, известный разрыв. `Visibility::bounded()` проверяет каждую запись через `decideAt()`, хотя есть `decideMany()`. Перевод меняет форму SQL и способ отказа источника. Колонки предикатов здесь не при чём: `PredicateCompiler` держит списки `connection|table` (`PredicateCompiler.php:22`, `:233`), `build()` использует один компилятор на все `constrain()`. `relation()` всё ещё рефлексия без кэша на узел `exists`, это на узел предиката, не на строку ресурса.

`P15` partly closed. Кэш allow/deny не делался. Сигнатуры методов политик (`RuntimeInvoker`) помнятся по классу и методу, а вызывается свежая closure, не первый экземпляр. Покрытие роли одним правом в `AuthorityStage::covers` помнится на решение. `PrepareStage` по-прежнему заново разбирает папки. HTTP и authorization: 422 passed. Остаток:

- `PrepareStage.php:171`. Каждый Grants-чек в одном `decideMany` вызывает `PanelSources::of()`, а `PanelSources.php:85` передаёт `null` в `PanelDiscovery::resolve()`. Каждый чек заново хеширует и разбирает папки панели, затем `BatchInputs.php:193` выбрасывает эти источники и оставляет первый `ReadAttempt`, когда панель, субъект, тенант и режим чтения совпали. Приватный снимок, возвращённый в `resolve()` аргументом `$cached`, поведение сохраняет: хеш файла всё равно считается, изменившееся дерево всё равно разбирается и всё равно бросает, читающая попытка не меняется, allow/deny и SQL те же. Сам `of()` пропускать нельзя. Поздний `make()` фабрики может дописать состояние контейнера или gate.
- `AuthorityStage.php:298`. `$wildcards` помнит только список шаблонов. Каждый вклад той же роли, глобальный и контекстный, или несколько источников, снова делает `in_array` и `PatternMatcher::coversValidated()` для одного права этого решения. `bool` на ключ роли рядом с `$wildcards` поведение сохраняет: `coversValidated` чистый и не бросает, строка права на этот `qualify()` фиксирована.
- `RuntimeInvoker.php:25`. Каждый policy `before` и ability строит новый `Closure::fromCallable` и новый `ReflectionFunction`. Один `decideMany` по той же политике делает это на каждый чек. Мемо только метаданных параметров, ключ класс и метод (id объекта для настоящей closure). Выбор слота по-прежнему смотрит живой ресурс и `accepts()`, `container->call` по-прежнему вызывает хук.

`P16` held. `AuthorityStage.php:224`, `:245`, `contributionEligible` на `:327`, `BatchEvaluation.php:357`, `PolicyDecider.php:43`. Классы ролей, условия грантов и evaluator scope делаются через `container->make` на каждый вклад (evaluator ещё раз на неглобальный вклад). Та же политика делается на каждый чек. Приватный мемо экземпляров меняет решения: сегодня каждый вклад получает свежий объект, поэтому `allows()`, `before()` или кэш запроса внутри evaluator не протекает в следующий. Общие экземпляры могут перевернуть allow/deny и SQL внутри evaluator.

### Чтение базы

`P17` open, тот же набор строк, другая форма запроса. `DatabaseSource.php:1343`. Выбор видимости (`materializeSelection` / `contextsCovering`) грузит свидетелей через `chunkById(500)`. Субъект с более чем 500 строками делает ещё один `SELECT` на каждые 500 строк вплоть до 10000, хотя все строки и так остаются в памяти. Один `ORDER BY id LIMIT 10001` возвращает те же упорядоченные строки и ту же ошибку бюджета. Не делалось: движки на новых образах не запускались.

`P18` held. `DatabaseSource.php:1014`. После 100 scope пакетный путь делает один `SELECT` грантов на кусок и на вид, а `assignmentRowsMany` на строке 1061 умножает это на куски id субъектов по 500. Более широкий или один оператор должен уложиться в потолок SQLite в 999 параметров и меняет и SQL, и склейку `orderBy id` по кускам.

`P19` open, осторожно. `LockedReads.php:98`. `catalog()` заново выбирает все динамические права тенанта на каждый вызов. Одна проверка role-grant читает таблицу дважды до любой записи права, миграция ключа роли читает её снова на каждый грант. Повторное использование строк до тех пор, пока эта мутация не пишет `permissions`, возвращает тот же каталог. Рядом pipeline сказал обратное для операции под локом: кэш каталога внутри одной операции сменит accept/reject, потому что более позднее изменение под тем же локом должно видеть права, вставленные этой операцией. Повтор можно держать только до записи `permissions`, не дольше.

`P20` held. `DatabaseSource::permissionRows()` читает все динамические права без потолка. Потолок меняет панели, у которых прав больше потолка.

### Doctor, что не схлопнули

`P21` partly closed. `RouteChecks` помнит список атрибутов по классу и методу и уже разрешённый gathered middleware маршрута для `panelOf` и `missing`. `covered()` по-прежнему разрешает список целиком, чтобы не разъехаться с дедупликацией одного вызова. Дальше было: `RoutesChecks.php:42`. `getRoutes()` один раз, но каждый маршрут заново разрешает middleware. `panelOf` (через чек) и вне панели `missing` оба зовут `app(Router::class)` и `resolveMiddleware()` по одному собранному списку. `gatherMiddleware()` на маршруте кэшируется, `resolveMiddleware()` нет и заново разворачивает алиасы и группы. На строгой панели `covered` снова разрешает префиксы `azguard.can:` / `can:` на каждый маршрут, а `attributes()` дважды отражает ту же цепочку контроллера (внутри `covered`, затем цикл). У чека `Router` уже внедрён. Мемо на один прогон разрешённого middleware, префиксов и списка атрибутов не меняет id и серьёзность: каждый удар остаётся `routes.checks` / error с тем же маршрутом и правом.

`P22` held. `ConfigValid.php:63` заново разбирает весь `azguard.*` через `AzGuardConfig::fromRepository()`, хотя `Doctor::config()` уже сделал это (`Doctor.php:247`), а `Doctor::secrets()` делает это в третий раз (`Doctor.php:285`). Подстановка `$context->config()` меняет находки: при `ConfigurationException` метод `Doctor::config()` оставляет уже загруженный конфиг (`Doctor.php:248–249`) и теряет ошибку загрузки `config.valid`, ради которой существует второй разбор.

### Прочее, что не код дефекта, но проблема прогона

`P23` open. `tests/Contracts/Sources/CapabilityMatrixCoverageTest.php`. `builtInCapabilityExecutionErrors()` поднимает один процесс Pest на сценарий, последовательно: 26 имён плюс один процесс ожидаемого провала. Эти 26 имён сидят в 8 файлах (`QueryParity` 7, `FilamentSource` 6, `GateSource` 4, этот файл 4, `RelationTenant` 2, по одному `RelationSource`, `DatabaseCache`, `StableCache`). Тесты folder inherit/isolated имеют по два набора данных; одно имя фильтра уже гоняет оба. Один Pest на файл, до 4 процессов через `Process::start()`, без второго `--parallel` под воркером CI. Фильтр из полных `preg_quote` имён. JUnit-кейсы должны совпасть с этим множеством, иначе пропавшее имя закроется соседним совпадением. Один отрицательный `--filter` оставить. На этом файле фильтровать только четыре названия сценариев, чтобы `requires an executable…` не вошёл снова. Удалить сторож выполнения нельзя: останется статический поиск `it('…'`, а доказательство изоляции и `fail-on-empty` пропадёт.

`P24` open. `tests/Feature/Bench`. `LoadBenchSmokeTest` уже гоняет `bench/bin/run.php --tier=smoke`: один посев (40 пользователей, 500 постов) и все 14 профилей, включая `cache-churn`, `visibility-branches`, `lock-contention`, `filament-pagination`. `AuditProfilesTest` стартует эти четыре ещё раз, то есть сеет ещё четыре раза и дважды компилирует ветки 999, 1000 и 10000. Эти четыре проверки можно сложить в тот же JSON: churn `iterations_per_worker=10`, rss и потолки 1024; стадии `branches-999|1000|10000` с `visible` / `overlimit`; contention `probe.lock_wait|lock_hold` и ключи отката; filament `count` и `page`. Оставить `Fixtures/Bench/contention.php` (4 писателя и 1 читатель; smoke contention это 1+1) и `warmup-failure.php`. Вызовы `compare.php` это голый PHP без Testbench. Урезать профиль smoke или `branches-10000` нельзя: пропадёт «проверки каждого профиля прошли» или доказательство `selection_budget`.

`P25` held. `ParityMatrixTest`. SQLite гоняет все 8 семян в одном процессе: 432 решающих случая (3×4×3×2×6) × 8 × audit выкл/вкл, половина назначений уже на одном семени (720 × 2). `unsupported.json` пишет `seeds=1-8` и `sql,sql+audit`. `PARITY_SEEDS=1,2` заставляет `isComplete()` пропустить это равенство. Семёна 1–2 всё ещё покрывают нечётный Isolated против чётного Required, Draws 3–8 пропадают. `i9-findings.json` восьми семян не требует. Сокращения с теми же утверждениями нет.

`P26` held. `prefer-lowest` оставлен на трёх PHP. Снятие режет SQLite с 6 ячеек до 3 и теряет пол Laravel 13. Имеет смысл одна lowest-ячейка на PHP 8.3 в nightly, не ноль.

`P27` held. Пол PHP `^8.3`. Локально 8.3.36. В `StorageReadSession` есть ветка SQLite `BEGIN` для PHP ниже 8.4.

`P28` held. MariaDB 10.11 снята с CI и осталась в Compose, в DDL и в ветках драйвера (снимок блокировок, пауза 120 мс, unbuffered regressions). Удаление кода это отказ от движка.

`P29` held. Замена панельного лока на лок субъекта не подтверждена замером. `docs/rejected-optimizations.md`, профиль `lock-contention`. Ждущий проб включает round-trip базы, проб удержания кончается на commit или rollback.

`P30` held. Подпись контекста гранта в таблице часто остаётся сырым ключом. `describe()` в фазе Assignment без субъекта возвращает null, ячейка равна ключу. Передать субъекта гранта или фазу `Inspection` меняет текст ячеек и добавляет чтение модели. Мемо каталога это не чинит. Запись грантов сама по себе дыр не показала: схема полей один раз на вид, проверка хранилища один раз на экземпляр `Storage`, каждый write идёт через `ChangeValidator::finalize` до `GrantWriter` / `ActionWriter`.

## Проверено и дефектом не признано

- Дубли Folder, Relation и Gate. Нормализованный проход не нашёл одинакового куска от 12 строк. `FolderSource::pairedEnum` (`FolderSource.php:535-552`) и `PanelDiscovery::policyFor` (`PanelDiscovery.php:436-453`) оба читают `#[PolicyFor]`, но discovery дополнительно требует string backing. `FolderSource::accepts` (`557-587`) и `PanelDiscovery::target` (`388-429`) оба сверяют строку права с кейсами enum, общая часть это цикл в четыре строки, сообщения разные. `RelationPredicateQuery` и `RelationScopeQuery` повторяют `$this->refuse()` кусками по три строки, наборы override разные, `upsert` специально с `mixed`. Общий трейт рискует потерять override и снова открыть чтение или запись.
- Провайдеры. Панель, scope и актор `scoped` (`CurrentPanel`, `CurrentContext`, `WithinContext`, `ActingActor`, `Authorizer`, `PermissionSetCache` в `AzGuardServiceProvider.php:100-111`). `ActingActor` читает пользователя гарда в каждом `resolve()`. `PanelContext` синглтон и держит только `WeakMap` работ очереди. `AzGuardFilamentServiceProvider` тенант, пользователя и панель не связывает. `FilamentTenantResolver` читает `Filament::getTenant()` на каждый resolve.
- `catch (Throwable)` в `packages/core/src` и `packages/filament/src` не превращает ошибку в allow, пустое ограничение видимости или пропуск проверки. Стадии пайплайна пишут ошибку и возвращают `Decision::deny` или `Decision::failed`. `Visibility::visibleTo` пробрасывает `VisibilityNotSupportedException`. `FilamentGate` и `GateBridge` возвращают `Response::deny`. `Authorizer::admissionScope` и `roleScopes` возвращают null, `PanelAdmission` считает это отказом во входе.
- Клон соединения в `StorageReadSession.php:79` и сброс PDO в деструкторе (`92-96`) на 13.39 всё ещё нужны. `Connection::__clone` вызывает `useDefaultQueryGrammar()`, грамматика хранит это соединение, цикл остаётся.
- `class_uses_recursive` снаружи `ClassTraits::has` в пакете Filament не осталось.
- Сигнатура `upsert` в `RelationPredicateQuery` на 13.39 это `upsert(array $values, array|string $uniqueBy, ?array $update = null)`. Файл `v13.0.0` из-за обрыва сети не скачан, поэтому комментарий про расхождение сигнатур на каждом патче 13 не подтверждён и не снят.

## Не проверено живьём

`U1` `bin/worker-smoke.sh` ставит `laravel/laravel:^13.0`, Octane оставлен `2.12.0`, RoadRunner-смок не запускался. Если пин не принимает Illuminate 13, worker-job сломается.

`U2` Образы `mysql:9.7.4` и `postgres:18.7-alpine` прошли только `docker compose config`. Контейнеры не стартовали, SQL-полоса не гонялась. По документации, не по живому серверу, ничего в `packages/core` эти версии не снимают:

- `StorageReadSession.php:143`. `START TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY` и `SET TRANSACTION` плюс `START TRANSACTION READ ONLY`. PostgreSQL 18 и MySQL 9.7 эти формы всё ещё описывают. Комментарии в коде ещё ссылаются на руководства PostgreSQL 16 и MySQL 8.4.
- `HostKeyColumns.php:31`, `StorageSchema.php:70`. Целые через Laravel идут как `bigInteger` / `bigIncrements` / `smallInteger`. Грамматика MySQL в поставке пишет голые `bigint`, `int`, `smallint`, не `INT(M)`. MySQL 9.7 всё ещё принимает ширину вывода целого и помечает её устаревшей. `packages/core` не вызывает `boolean()`.
- `StorageSchema.php:126`. `CHECK` сравнивает булевы предикаты `(key = 'global') = (type IS NULL AND id IS NULL)`. Список миграций PostgreSQL 18.0 эту форму не снимает.
- `StorageReadSession.php:312`. Единственный атрибут PDO в `packages/core` это `PDO::ATTR_DRIVER_NAME`.
- `docker-compose.yml:5` и `:26`. Флаги MySQL: `--disable-log-bin`, `innodb_flush_log_at_trx_commit=0`, `innodb_doublewrite=OFF`, `innodb_flush_method=O_DIRECT_NO_FSYNC`. Флаги PostgreSQL: `fsync=off`, `synchronous_commit=off`, `full_page_writes=off`. Каталог данных образа 18 это `/var/lib/postgresql/18/docker`, том на `/var/lib/postgresql`. `wal_level=replica`, `max_wal_senders`, `wal_keep_size` PostgreSQL 18 ещё описывает. Реплика использует физический `pg_basebackup`, не логический output plugin.
- Текст «Features Removed in MySQL 9.7» дочитан не был: сеть оборвалась.

`U3` Полный SQLite-прогон и coverage с PCOV локально не измерялись. Замер есть только у `tests/Unit` без `extended`: 921 тест, 259968 утверждений, стена 13.49 с одним процессом и 4.77 с четырьмя. Run CI `42160723154` от 2026-11-06 был красным. Coverage на Xdebug занимал стену 19 мин 57 с и упал. Шесть DB-job того run суммарно 1132 с раннера. Оставшиеся по тем цифрам MySQL 13 (247 с) и PostgreSQL 13 (218 с) дают 465 с. Это не новый зелёный прогон.

`U4` Redis-тест файла `EnvelopeIntegrityTest` локально не запускался: connection refused. Остальные тесты того файла и оба редактора грантов были зелёные (42 passed) до отсечения Redis. Поддельный конверт на array-store после перехода на `all(false)` зелёный отдельно: 8 утверждений, объект не просыпается.
