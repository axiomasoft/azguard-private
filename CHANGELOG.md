# Changelog

All notable changes to AzGuard are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning: [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- **filament:** Закрыты обходы авторизации через custom actions/bulk, включённые inline-редакторы, raw column callbacks и relation managers без трейта. Enforced-панели проверяют bulk-записи до callback; обычные панели сохраняют поведение Filament. Редактор динамических прав сохраняет выбранные панель и tenant после create/update/delete. Тесты экспорта используют штатную цепочку заданий закреплённого Filament.


- **filament:** Генератор прав `azguard:filament:generate {--filament-panel=} {--panel=} {--authority=grants} {--with-policy} {--only=*} {--force} {--dry-run}`: по ресурсам, страницам и виджетам Filament-панели создаёт enum прав в папке AzGuard-панели через `azguard:make:permission` (по enum на ресурс в группе `{навигационная группа}/{slug}`, `Pages`, `Widgets`); ключи — те же, что проверяет панель (`FilamentKeys`), права редакторов AzGuard генерируются как у любого ресурса. Каждый enum получает метку `@api` `Attributes\ForFilament(Class)`. `--with-policy` при grants создаёт policy с `#[PolicyFor]`, которая только пропускает (grant всё равно нужен); `--authority=policy` даёт `#[PolicyOnly]` и policy, которая запрещает до реализации. Режим `FilamentDefinitions::Resources` генератор не обслуживает. `@api` `Diagnostics\FilamentDefinitionsCheck` (`doctorChecks([FilamentDefinitionsCheck::class])`): `filament.stale_definition` (предупреждение — класс из `#[ForFilament]` больше не в Filament-панели) и `filament.missing_definition` (ошибка — класс проверяет право, которого нет в панели).
- **core:** `azguard:make:permission` получил `--authority=grants|policy` (policy → `#[PolicyOnly]` и обязательный файл policy, методы которого возвращают `false`) и повторяемый `--case=Name=local.key` (заменяет пять CRUD-кейсов, подпись `#[Describe]` — заголовок имени). `azguard:make:policy` для enum с `#[PolicyOnly]` пишет методы, возвращающие `false`, а не `true`. В stub `permission.stub` добавлены `{{ authority }}` и `{{ cases }}`, в `policy-method.stub` — `{{ comment }}` и `{{ result }}`: опубликованные stubs старого выпуска обновляются `azguard:stubs --force`.
- **filament:** Страницы только для чтения `Pages\PanelsPage` (`azguard-panels`, право `pages.azguard-panels`) и `Pages\DoctorPage` (`azguard-doctor`, право `pages.azguard-doctor`), регистрация по `resources(panels:, doctor:)`. «Панели» показывают все панели AzGuard (и без `DatabaseSource`), их источники, плагины, итоговые настройки с происхождением и схему прав; «Doctor» показывает находки `azguard:doctor` по важности без секретов, запускает doctor при открытии и по действию «Check again», а не на каждом рендере.
- **core:** `@api` `Diagnostics\PanelOverview` (`all()`, `panel()`) — единый источник данных панелей для `azguard:panels:list` и страницы «Панели»; вывод команды не изменился.
- **filament:** Свои поля из схемы и точка расширения формы. `@api` `Contracts\FilamentFormExtension` (`appliesTo(PanelSchema, FieldTarget)`, `components(PanelSchema, FieldTarget)` — компоненты по имени поля схемы); `AzGuardPlugin::formExtensions(...)` принимает классы и экземпляры, класс резолвится контейнером при каждой сборке формы (`resolveFormExtensions()`), так что расширение не делит состояние между панелями и запросами. `Forms\SchemaFields::for(..., $extensions)` подставляет компонент применимого расширения вместо базового (для одного поля побеждает первое зарегистрированное); компонент для имени, которого нет в схеме цели, или не поле формы с этим именем — `InvalidConfigurationException` при сборке формы. Значения полей уходят писателю как отправлены: ключ поля, которого нет в схеме, больше не отбрасывается формой, а отклоняется писателем (`InvalidChangeFieldsException`), и ошибки писателя по полям и сроку показываются как ошибки открытой формы выдачи или правки — без записи и без новой версии. Смена панели или tenant в форме пересобирает набор полей и очищает их значения.
- **filament:** Редакторы назначений. `@api` `Resources\RoleGrantResource` (`azguard-role-grants`) и `Resources\PermissionGrantResource` (`azguard-permission-grants`), права `{slug}.view_any|view|create|update|delete`, регистрация по `resources(roleGrants:, permissionGrants:)`. Таблица — сохранённые выдачи управляемой панели (панель без `DatabaseSource` не предлагается) и tenant через `GrantManager::page`: простой `Paginator`, курсор страниц хранится на сервере (`#[Locked]`) и сбрасывается при смене фильтров, выбор — только текущая страница; колонки субъект/роль или право/контекст с подписями директорий, origin, срок, кто выдал, свои поля; фильтры роль/право, субъект, контекст, состояние (`active` по умолчанию, `expired`/`orphaned`/`any` для отзыва), «истекает до», «кто выдал». Форма выдачи в порядке панель → tenant → субъект (поиск директорией, лимит 50) → роль (grantable) или право (решаемое выдачами; PolicyOnly не предлагается и отклоняется писателем `PermissionNotGrantableException`) → тип контекста → контекст (поиск директорией с целевым субъектом, ролью и полями) → срок → свои поля; смена панели или tenant очищает выбранное после неё. Каждое значение payload перепроверяется на сервере (`describe()` директорий, схема панели, `TargetSelector`), запись — от имени редактора (`AzGuard::actingAs`). Правка строки — срок и свои поля с отпечатком, запомненным при открытии формы: изменённая с тех пор выдача не перезаписывается. Отзыв строки и выбранных строк — одна `revokeMany`; id другого tenant отклоняет всю операцию. Действие «Почему?» (таблица и строка) показывает ответ `explain()` целевой панели: решение, причину и шаги с редактированными секретами. `@api` `Forms\SchemaFields` — базовые поля формы по `FieldSchema` (string, int, bool, date, enum, model; список значений).
- **core:** `GrantFilter` получил именованные `expiresBefore` (выдачи со сроком раньше момента; бессрочные не подходят) и `grantedBy` (`ActorRef` сохранённого актора; системный актор — по типу); оба входят в условия, к которым привязан курсор страницы, и сохраняются `after()`.
- **filament:** Каталоги ролей и прав по схеме панели. `@api` `Resources\RoleResource` (`azguard-roles`, права `azguard-roles.view_any|view`) — read-only каталог кодовых ролей выбранной управляемой панели: ключ, подпись, класс, выдаётся вручную/автоматически, суперадмин, области с фильтрами, права с authority; страниц и действий записи нет, панель и роль страницы просмотра — `#[Locked]`, payload с `class_name`/`definition`/`permissions`/`superAdmin` ничего не находит и ничего не меняет. `@api` `Resources\PermissionResource` (`azguard-permissions`, права `azguard-permissions.view_any|view|create|update|delete`) — все права управляемой панели с `DatabaseSource::make()->dynamicPermissions()`: права enum только для чтения с доменом, группой и authority (PolicyOnly — badge «решает политика» с `decidedBy`, без галочки), динамические права tenant создаются, переименовываются, перегруппировываются и удаляются вместе с выдачами через `PermissionManager` от имени редактора (`AzGuard::actingAs`). Цель редактора: панель ∈ `manages` ∩ writable; tenant guard-панели — tenant запроса, панели без tenants — глобальный, иначе — поиск в директории tenants панели (лимит 50) с перепроверкой `describe()` на каждом чтении и записи; панель вне выбора или неизвестный tenant — 403. Плагин регистрирует ресурсы по `resources(roles:, permissions:)`.
- **core:** `@api` `Directories\PanelDirectories` и `PanelAccess::directories()`: директории субъектов, tenants и областей панели для интерфейса — те же, что выбирает внутренний резолвер (объявленные в коде или по умолчанию); своей логики поиска нет.

- **filament:** Авторизация всех поверхностей Filament по идентичности ресурса. `@api` `Authorization\FilamentGate` (`resource`, `relationManager`, `page`, `widget`, `visible`, `before`) решает в guard-панели плагина: ресурс — `{slug}.{ability}` с записью как ресурсом решения, страница — `pages.{slug}`, виджет — `widgets.{name}`; ресурс по модели не выбирается никогда. Трейты `Concerns\AuthorizesResource` (`getAuthorizationResponse`, `getEloquentQuery` с exact visibility `{slug}.view` до count/page/global search; свой запрос — в `modifyEloquentQuery()`), `AuthorizesRelationManager` (related resource или `$azguardResource`; таблица — видимые связанные записи), `AuthorizesPage`, `AuthorizesWidget`. При `enforce()`: нет пользователя, модель без `AzGuardSubject`, субъект не принят панелью, право без definition — отказ; ресурс, страница или виджет без трейта (или с переопределёнными методами трейта) — `InvalidConfigurationException` (`filament_enforce`) при boot плагина; без `enforce()` известные права решает AzGuard, остальное — Filament. Bulk delete/force delete/restore решают каждую выбранную запись (`delete`/`force_delete`/`restore`), attach/associate предлагают и принимают только видимые записи. Экспорт в enforce-панели идёт через `Exports\AuthorizedPrepareCsvExport` и `Exports\AuthorizedExportCsv`: при исполнении job оставляет только строки, которые пользователь экспорта ещё видит в guard-панели и tenant экспорта; экспорт без такой проверки не запускается. `Gate::before` пакета — только `false`/`null`: в enforce-панели отказывает ability Filament на модели без policy и без Gate-ability, Filament-панели без плагина не затрагивает. Inline editable columns в ресурсах AzGuard не поддерживаются без `disabled()`. Exact visibility недоступна в панели с ролью `GrantedAutomatically` (ошибка настройки), модель пользователя Filament должна быть субъектом guard-панели (`for()`).

- **filament:** Плагин `AzGuardPlugin` (id `azguard`): `guardPanel`, `manages`, `enforce`, `definitions(FilamentDefinitions::Enums|Resources)`, `authority`, `abilities`, `exclude`, `resources(...)`, `formExtensions(...)` хранятся в экземпляре плагина панели, значения по умолчанию — `config/azguard-filament.php` (публикация тегом `azguard-filament-config`); вход в guard-панель `azguard.panel:{guardPanel}` повторяется на Livewire update. Ключи ресурсов, страниц и виджетов (`{slug}.{ability}`, `pages.{slug}`, `widgets.{name}`, override `$azguardKey`) проверяются на грамматику и уникальность при boot панели; `FilamentSource` даёт definitions режима Resources с явным authority, `FilamentTenantResolver` отдаёт tenant Filament guard-панели. Пакет Filament не пишет в хранилище напрямую (arch-тест).
- **core:** Общий кэш наборов прав хранит запись строкой и читает её с белым списком классов ядра: Laravel 13 по умолчанию (`cache.serializable_classes` = false) превращал объекты массива в неполные классы, и каждое чтение обходило кэш и шло в БД.
- **core:** Дополнительно исправлены происхождение ролей и источников в отпечатке кэша, отказ exact visibility при отсутствии обязательной модели области (включая mixed grants), продолжение prune после вложенного revoke, передача `--env` дочерним командам установщика и сохранение допустимых dotenv-объявлений без `--force`. Контрактный наблюдатель учитывает выполняемые `EXPLAIN ANALYZE` записи.
- **dev:** HTTP-проверки учитывают версии Laravel; consumer-фикстура проверяет настоящий RefreshDatabase, writer и shared cache после свежей установки. Добавлены отдельные MariaDB и replica CI-гейты. Полная приёмка этих исправлений остаётся открытой в P6.11; короткие проверки и независимое статическое ревью её не заменяют.

- **core:** Аудит выполненных фаз P1–P6: исправлены обход пользовательского scope resolver в batch/visibility, расширение выдачи через native OR/UNION в связанных SQL-предикатах, удаление заменённого или продлённого гранта после nested pipe, скрытый повтор continuation, числовые panel IDs/prefixes, циклические fields/identity arrays, fingerprint native Gate, диагностика повреждённого каталога и потеря владельца одинаковых ролей от разных plugins/provider. Для несовместимых raw relation predicates требуется явная SQL-группа; отказ не изменяет исходный query builder.
- **core:** `InteractsWithAzGuard` поддерживает настоящий `RefreshDatabase` через явный тестовый адаптер с проверками manager/root transaction/PDO/fiber и отдельным cache namespace каждой baseline-транзакции. Обычные неизвестные транзакции по-прежнему отклоняются. Исправлены plugin contract lifecycle/dependencies и распознавание SQL-записей с комментариями/CTE в контрактных наблюдателях.
- **core:** HTTP-атрибуты учитывают middleware aliases/groups/exclusions без повторных checks; генератор регистрирует провайдер только в действующем `panels.providers`; установщик запускает migration/doctor с новым bootstrap и останавливается после ошибки генератора. Объявлена зависимость `illuminate/auth`, отсутствие cron-парсера даёт ошибку конфигурации с подсказкой. Пустой permission batch проверяет конфликт панели; P03 покрывает HasAzGuard.
- **dev:** Включён существующий Redis CI job, обычные suite/coverage/mutation отделены от engine/Redis/replica стендов без снижения порогов; MariaDB lock-wait observer читает структурированные таблицы. Arch проверка удаления 0.3 допускает пустые локальные каталоги, продолжая запрещать файлы, ссылки и legacy autoload. Обновлены актуальные инструкции разработки; подробные findings и ограничения проверок записаны в активном плане.

- **core:** Механизмы Laravel вместо своих. `optimize` и `optimize:clear` запускают `azguard:catalog:cache|clear` через `ServiceProvider::optimizes()` (есть с Laravel 11.27.1; на более старой 11.x хук не регистрируется, doctor `catalog.cached` предупреждает для production). `php artisan about` печатает раздел AzGuard: версию, панели с источниками, хранилищем и версией состояния, именованные источники, тип host keys, свежесть кэша каталога (общий с doctor ответ `Panels\PanelRegistry::cacheState()`: `missing`/`stale`/`current`); недоступное хранилище показывается как `unavailable`, без исключения и секретов. `azguard.panel` кладёт id панели в скрытый Laravel Context (`illuminate/log` добавлен в `require` ядра), и queued-job, поставленный из запроса, на время исполнения работает в этой панели: короткие имена идут в неё, а не по правилу по умолчанию; job из консоли без ключа — по правилу по умолчанию. Панель до job (в том числе запроса при `sync`-очереди) возвращается после успеха, исключения и провала; id панели, которой после деплоя нет, не заменяется панелью по умолчанию: короткие имена job отклоняются `UnknownPanelException`, полные имена работают. Context несёт только id панели: субъект, tenant и ресурс job передаёт ссылками и авторизует заново.

- **dev:** удалён справочник `legacy/0.3/` и его исключения в `phpstan.neon`, `pint.json`, `.gitattributes`; `tests/Regression/LegacyIsolationTest.php` заменён arch-тестом `tests/Arch/LegacyNamesTest.php` (каталога `legacy/` нет, он вне autoload, старые имена 0.3 отсутствуют в `packages/`, `tests/`, `bin/`, `composer.json`, `.github/`). Справочник 0.3 читается из git: `git show 491980a:legacy/0.3/<путь>`.

- **core:** `azguard:install [--migrate] [--force] [--connection=] [--host-keys=] [--panel=]` — публикует `config/azguard.php` с выбранным `ids.host_keys` (`string|bigint|uuid|ulid`, иное — код 2 до любой записи), записывает `AZGUARD_DB_CONNECTION` в `.env` (имя подключения проверяется по `config/database.php`), предлагает создать первую панель через `azguard:make:panel`, печатает миграции AzGuard и в конце запускает `azguard:doctor`. `migrate` выполняется только с `--migrate`; его ненулевой код возвращается как есть и doctor не запускается, при успехе код выхода — код doctor; без `--migrate` код отражает шаги установки. Существующие `config/azguard.php` и ключ `.env` без `--force` не меняются.

- **core:** Генераторы структуры панели на `Illuminate\Console\GeneratorCommand` и публикуемых стабах (`packages/core/stubs/*.stub`, `azguard:stubs` кладёт их в `stubs/azguard/`, где генераторы берут их раньше стабов пакета; опубликованный стаб с плейсхолдером, который релиз не заполняет, — ошибка с подсказкой `azguard:stubs --force`). Корень и namespace — `azguard.scaffold.*`, имена каталогов — `azguard.discovery.*`. `azguard:make:panel {Panel} [--model=]` — `{scaffold.path}/{Panel}/{Panel}GuardPanelProvider.php` с `getId()` (kebab-case имени), `for(model)` (модель из `auth.providers.users.model` или `--model`) и `permissions([DatabaseSource::make()])`, провайдер дописывается в `azguard.panels.providers` опубликованного `config/azguard.php` (файла нет или списка не найти — сообщение, файл не создаётся и не меняется). `azguard:make:permission {Panel} {Group} [--model=] [--policy] [--abilities]` — `Permissions/{Group}/{Name}Permission.php` (string-backed enum с `#[Resource]`, `#[RequiresGrant]` и пятью случаями `view_any|view|create|update|delete`, ключ из пути группы: `Sales/Orders` — `sales.orders.view`), `Policies/{Group}/{Name}Policy.php` с `#[Decides(Case)]` на каждый случай и `Abilities/{Group}/{Name}Abilities.php` (DTO над `SubjectAccess::hasPermission`); `Name` — единственное число последнего сегмента группы. `azguard:make:role {Panel} {Name}` (`#[Role('<kebab-key>')]`), `azguard:make:policy {Panel} {Group} [--enum=]` (единственный enum группы либо `--enum` с `#[PolicyFor]`; нет enum, несколько enum или вторая непривязанная политика в группе — ошибка с подсказкой `--enum`/`#[PolicyFor]`), `azguard:make:source|plugin|restriction|pipe {Name} (--panel=|--shared)` (`Sources/` с `#[AsSource]` и `--grants|--permissions|--roles|--policies`, `Plugins/` с собственной типизированной `make()` без options, `Restrictions/`, `Changes/`), `azguard:make:models {Panel} [--storage=]` (наследники `RoleGrant`/`PermissionGrant` в `{Panel}/Models/` с `azguardFields()` и миграция колонок). Генератор сначала планирует все файлы и не пишет ни одного, пока один из них существует: перезапись только с `--force`; код выхода 0 готово, 1 файл существует, 2 неверный ввод (имя панели, группы, класса, незаданный `--panel`/`--shared`, отсутствующий каталог панели). Сгенерированная панель находится `FolderSource` (enum, политика по паре группы D56, роли), даёт тот же каталог и решения после `azguard:catalog:cache`, а код проходит Pint и PHPStan проекта.

- **core:** Тестовый kit `AzGuard\Testing` для приложений и авторов расширений. `InteractsWithAzGuard`: `actingAsWithPermissions($subject, [...], on:, panel:)` выдаёт права через пайплайн изменений актором `system` с причиной `testing` и входит на guard панели; `actingAsSuperAdmin($subject, panel:)` выдаёт суперадмин-роль панели (нет такой — `UnknownRoleException`). `AzGuard::fake()` возвращает `AzGuardFake` — шпион поверх настоящего движка: записывает проверки (`RecordedCheck`) и изменения (`RecordedChange`) из событий пакета, в том числе у панели без `trace_decisions` и при `Event::fake()` хоста, и ничего не решает вместо движка — режима allow-all нет. Ассерты: `assertChecked|assertNotChecked`, `assertDecided($subject, $permission, Effect)`, `assertRoleGranted|assertRoleRevoked`, `assertPermissionGranted|assertPermissionRevoked($subject, $x, on:)` (`on: null` — выдача на весь tenant, `AnyAssignmentScope::all()` — в любой области). `FakeSubject` — модель-субъект без таблицы, `FakeSource` — in-memory источник выдач и ролей для тестов приложения. Движок читает права только вне транзакции, которую не открывал сам: под `RefreshDatabase` (оборачивает тест в транзакцию) проверка остаётся отказом `source_error`, а не разрешением — используйте `DatabaseMigrations`/`DatabaseTruncation`. Контрактные наборы-трейты `Testing\Contracts\{Source,Restriction,Hook,Plugin,SubjectResolver,AssignmentScopeResolver}ContractTests` проверяют реализацию против настоящего AzGuard; репозиторий исполняет их на встроенных источниках (Folder, Database, Relation, Gate), плагине аудита и фикстурных реализациях и доказывает, что каждый набор падает на реализации, нарушающей гарантию. Внутренний `Events\EventObservers` — точка, через которую kit видит события, не заменяя диспетчер хоста.

- **core:** Команды обслуживания и выдач (`azguard:<area>:<verb>`). Читающие, с `--json`: `azguard:panels:list [--settings] [--sources] [--schema]` (итоговые настройки с происхождением значения, источники панели с их возможностями, именованные источники и плагины, схема прав и ролей), `azguard:sources:list` (имена фабрики источников — класс `#[AsSource]` или `extend()`, параметры `azguard.sources` с вырезанными секретами: значения под именами с pass/secret/token/credential/key/private/dsn/auth и пароль в URL вида `scheme://user:password@host`, панели-пользователи; сконфигурированное, но не зарегистрированное имя тоже показывается), `azguard:catalog:list`, `azguard:roles:list` (кодовые роли read-only, способ назначения, суперадмин, области, число держателей действующих выдач в tenant), `azguard:grants:list {subject}` (все сохранённые выдачи субъекта в tenant во всех origin, истёкшие помечены, секретные поля скрыты), `azguard:permissions:show {subject} [--on=]` (роли и Grants-права в области; не решение). Изменяющие — только через пайплайн изменений с system-актором, причина которого — имя команды: `azguard:roles:grant|revoke` и `azguard:permissions:grant|revoke {subject} {role|permission} [--on=type:id] [--until=ISO-8601] [--field=key=value…] [--origin=]` (revoke — без `--until`/`--field`), `azguard:permissions:create {name} [--label=] [--group=]` / `azguard:permissions:delete {name} [--force]`, `azguard:roles:rename-key {role} {new} [--dry-run] [--force]` (перенос выдач прежнего ключа `#[FormerKeys]`; dry-run читает план под lock и ничего не пишет), `azguard:grants:prune [--panel=] [--tenant=] [--before=] [--dry-run]` (истёкшие выдачи с `GrantExpired`; без `--panel` — все панели с `DatabaseSource`; `--before` только назад во времени, будущая дата — отказ), `azguard:audit:prune [--panel=] [--before=] [--force]` (строки журнала старше `AuditPlugin::make(retentionDays:)`; `--before` только сужает; панель без плагина аудита — сообщение и код 0), `azguard:state:reset {panel} [--force]`. Общие правила: панель выбирает только `PanelResolver` (`--panel`, полное имя `panel:name`, класс роли, субъект) — неразрешимая или конфликтная панель — ошибка, а не выбор; `{subject}` — `type:id` или id, когда у панелей одна модель субъекта; панель с tenants требует `--tenant=type:id` до любого чтения и записи, операций «по всем tenants» нет (кроме обслуживающего `grants:prune` без `--tenant`); необратимые команды в production требуют `--force`; код выхода 0 — успех, 1 — отказ операции, 2 — неверный ввод (панель, tenant, субъект, опция, нет `--force`); сообщение непредвиденного исключения печатается только с `-v`. `azguard:state:reset` — новая ULID-incarnation и version+1 под той же блокировкой `panel_state`, что и любая запись панели (внутренние `ChangePipeline::reset()` и `Storage::renewIncarnation()`): токены, кэшированные решения и наборы прав старого состояния больше не проходят fence, `PanelStateTouched` с причиной `reset`; reset внутри открытой транзакции писателя — `InvalidConfigurationException`. Планировщик: при `schedule.enabled` и непустом `schedule.prune_expired` провайдер регистрирует `azguard:grants:prune` с этой частотой (метод частоты или cron). Имена и сигнатуры всех `azguard:*` зафиксированы снимком `tests/Fixtures/Console/commands.json`.

- **core:** `azguard:doctor [--panel=*] [--storage=*] [--production] [--json]` — проверка конфигурации, панелей, хранилищ, источников и плагинов до деплоя: `@api` `Diagnostics\Doctor`, значения `Diagnostics\{DoctorFinding, DoctorContext, Severity}` (`error`/`warning`), `@spi` `Contracts\Diagnostics\DoctorCheck` и `Contracts\Sources\ChecksHealth`. Проверки ядра: `config.valid`, `storage.migrated`, `storage.schema` (свежая сверка `storage_state`, типы колонок host keys, колонки своих моделей), `panels.valid`, `panels.plugins`, `panels.sources`, `panels.policies` (в том числе снимок discovery в кэше каталога), `policies.complete`, `roles.keys`, `routes.checks` (тот же сборщик, что у `azguard.panel`), `panels.relations`, `catalog.collisions` (префиксы и динамические права), `catalog.cached` и `catalog.build_id` (production), `roles.orphaned`, `grants.dead`, `fields.meta`, `membership.configured`, `consistency.reads`, `cache.store`, `gate.mode`, `direct_writes`; плагин аудита добавляет `audit.table`. `FolderSource` и `DatabaseSource` реализуют `ChecksHealth`; `PanelBuilder::doctorChecks([...])` принимает только `DoctorCheck` (объект или класс), иначе `DefinitionException`. Исключение внутри проверки — находка `<key>.failed` с классом исключения без сообщения драйвера, остальные проверки выполняются; недоступное хранилище — error, не пропуск. Пароли, DSN и секреты источников вырезаются из сообщений и деталей. JSON — список в порядке scope/key/message; код выхода 1 при ошибке, 2 для неизвестной панели или хранилища. Doctor только читает.

- **core:** Ревью P6.1–P6.3: `PanelAccess` запрещает подмену текущего tenant в запросе; строгий режим учитывает исключённые middleware; конфиг отклоняет невалидный cron и пути scaffold с диском/URI; дубли source id дают `duplicate_permission` вместо `definition`. Добавлены регрессионные тесты и проверки моделей `DatabaseSource` при сборке панели.

- **core:** Полный `config/azguard.php` и типизированный `AzGuardConfig`. Новые секции: `defaults.tenants.resolvers` и `defaults.scopes.resolvers` (имена классов `TenantResolver`/`AssignmentScopeResolver`, которые каждая панель пробует после собственных; класс без контракта — `InvalidConfigurationException`), `gate.enabled` (при `false` пакет не регистрирует `Gate::before`; `@can` и `Gate::allows()` панельных прав не видят, `AzGuard::check()` и `azguard.can` работают), `schedule.enabled` и `schedule.prune_expired` (имя метода частоты планировщика Laravel без аргументов, cron-выражение или `null`; регистрация задачи — позже, здесь только чтение), `scaffold.namespace` и `scaffold.path` (куда генераторы кладут папки панелей). Неизвестный ключ в корне и в любой секции — `InvalidConfigurationException` с перечнем допустимых; `AzGuardConfig::knownKeys()` совпадает со снимком `tests/Fixtures/Configuration/config-keys.json`. Файл публикуется тегом `azguard-config`, миграции — `azguard-migrations`. Чтение `config('azguard…')` и `Config::get('azguard…')` вне `Configuration\` запрещено arch-тестом. Каждая строка таблицы проверок запуска (07 §5) имеет тест в `BootChecksTest` и бросает исключение с кодом из таблицы во всех окружениях; для этого шесть проверок получили код таблицы вместо общего `definition`: scope-membership без адаптера — `invalid_configuration.membership`; обязательный tenant без membership-адаптера или владельца scope, роль с областью вне зарегистрированных — `invalid_configuration.tenant_scope`; несуществующая статичная роль `RelationSource` — `unknown_role` (`UnknownRoleException`); один физический storage под двумя id — `storage_mismatch`; `DatabaseSource` с незарегистрированным storage или моделью не той таблицы падает при сборке панелей (`invalid_configuration.storage`/`storage_mismatch`), а не при первой проверке.

- **core:** HTTP-вход в панель и проверки маршрутов. Middleware `azguard.panel:{id}` (`Laravel\Http\Middleware\EnterPanel`) выбирает панель через `PanelResolver`, выполняет `middleware([...])` панели, которых маршрут ещё не выполняет, и пускает пользователя guard панели, только если тот — принятая модель, член tenant/области запроса и держит хотя бы одну действующую роль панели (DB, автоматическую кодовую, relation) в разрешённой области; пока project не выбран, достаточно одной целой роли в подходящем project tenant. Прямая выдача и PolicyOnly allow не пускают; заданный `entry()` решается дополнительно (AND) в области запроса, veto действует и на суперадмина; ошибка источника или источник без выбора областей — отказ. Гость получает стандартный ответ аутентификации, отказ — ответ `onDenied()` (closure или redirect по имени маршрута/URL) либо 403. Внутри запроса панель и принятая область — текущие и снимаются в `finally` (исключение, redirect, следующий запрос Octane). `azguard.can:{permission}[,{param}]` (`Laravel\Http\Middleware\CheckPermission`) проверяет право пользователя на модели или области из параметра маршрута; enum пишется `Enum::Case`, панель выбирает `PanelResolver` (enum нескольких панелей остаётся `AmbiguousPanelException` и под `azguard.panel`). Атрибуты `#[CheckPermission(permission, on:, status:, message:, only:, except:)]` (на Laravel 13 — наследник `#[Middleware]`, его применяет роутер; на 11/12 тот же класс читает `azguard.panel`) и `#[SkipPermissionCheck]`; `azguard.panel` добавляет только те `azguard.can`, которых роутер не собрал (11/12 — все, 13.0–13.4 — с родительских контроллеров), поэтому проверка не выполняется дважды. Отказ — `AuthorizationException` со статусом и сообщением атрибута (по умолчанию 403 и `azguard::http.forbidden`), без объяснения решения. Строгий режим `requireRouteChecks()`: действие без `azguard.can`, Laravel `can`/`#[Authorize]` и без `#[SkipPermissionCheck]` — `MissingPermissionCheckException` (`missing_permission_check`) в local/testing и 403 в остальных окружениях. `Panel` получил `entry()`, `onDenied()`, `middleware()`, `requiresRouteChecks()`. Регистрируются только alias'ы `azguard.panel` и `azguard.can`; Blade-директив `@az*` нет.

- **core:** Фасад `AzGuard` и доступ к панели целиком. `AzGuard::panel($id)` возвращает `@api`-контракт `Contracts\PanelAccess` (`definition`, `inTenant`, `fromOrigin`, `scope`, `for`, `roles`, `grants`, `permissions`, `schema`, `decide`, `decideMany`, `explain`, `catalog`, `visibility`, `state`, `touch`) над готовыми `Authorizer`, менеджерами и построителем схемы; неизвестный id — `UnknownPanelException`. Панель, tenant и origin заданы при создании `Panels\ScopedPanelAccess` и не принимаются методами; `inTenant()`/`fromOrigin()` возвращают новый доступ, менеджеры чужого tenant/origin не видят записей (`find` → null), запрос другой панели или другого tenant в `decide*`/`explain` — `ConflictingPanelException`/`TenantMismatchException`; tenant-панель без tenant — `TenantRequiredException`. `AzGuard::check()` и `authorize()` выбирают панель правилом `PanelResolver` (как трейт модели); `authorize()` бросает `Illuminate\Auth\Access\AuthorizationException`, код причины — в `response()->code()`, объяснения решения в исключении нет. `withinScope()`/`currentScope()` работают на панели запроса либо default-панели (иначе `PanelNotResolvedException`); `actingAs()` задаёт актора изменений внутри колбэка: модель или `SubjectRef` — актор по identity, непустая строка — system-актор с этой причиной, пустая строка или не-модель — `InvalidArgumentException`. Внутренний `Scopes\ModelIdentity` — единственное чтение «чем модель является в панели» (tenant, контекст) для `SubjectAccess`, доступа к панели и фасада.

- **core:** Финальная проверка изменений перечитывает dynamic permissions после pipes; вложенное назначение после планирования каскада отменяет удаление permission вместе с выдачами, журналом и событиями. Подтверждённый `ChangeResult::state` учитывает успешные вложенные изменения даже при внешнем no-op. Внутренний `SchemaBuilder` исключён из публичного API-манифеста. Поиск директорий сохраняет буквальные `%`, `_`, `\\` и escape-символ на SQLite, PostgreSQL, MySQL и MariaDB.

- **core:** Повседневный API модели: трейт `Concerns\HasAzGuard` и контракт `Contracts\AzGuardSubject` — проверки `hasPermission`/`hasAnyPermission`/`hasAllPermissions`, `permissionSet`/`permissionNames`, роли `hasRole`/`hasAnyRole`/`hasAllRoles`/`roleNames`, `isSuperAdmin`, изменения `grantRole`/`revokeRole`/`syncRoles`/`grantPermission`/`revokePermission`/`syncPermissions` (через пайплайн изменений), `inTenant`, `azguard()`, `azguardDefaultPanel()`, `azguardRef()`. `guard(array|string $guarded)`: массив — прежний Eloquent mass assignment (та же модель), строка — неизменяемый `Concerns\SubjectAccess` панели; Auth, текущая панель и модель не меняются. `SubjectAccess` (final readonly): все методы трейта в фиксированной панели, `panel()`, `fromOrigin()` (только partition изменений и списков), `inTenant()`, `scope()`, `decide()`, `abilities()`, `roleGrants()`/`permissionGrants()` (write-guarded модели панели); `guard:` другой панели → `ConflictingPanelException`. `SubjectPanels`: `panels()`, `default()`, `guard()`, `permissions()` (только панели без tenants), `roles()`. Панель по-прежнему выбирает только `PanelResolver`; роли стали сигналами того же правила (`panel:key`, класс роли одной панели; нескольких — `AmbiguousPanelException`, ни одной — `UnknownRoleException`). Список ролей и набор прав на tenant-панели без tenant — `TenantRequiredException`, без агрегации; `permissionSet` — назначенные Grants-права (без PolicyOnly), не решение. Запись с ресурсом вместо контекста — `AssignmentScopeNotAcceptedException`.

- **core:** Менеджеры панели для редакторов: `@api`-интерфейсы `Contracts\Roles\RoleCatalog` (только `all()`/`find()` кодовых ролей как `RoleSchema`; по ключу, `panel:key` или классу, прежний ключ не находится), `Contracts\Changes\GrantManager` (`page`/`find`/`update`/`revokeMany` назначений одной панели, tenant и origin) и `Contracts\Changes\PermissionManager` (`all`/`create`/`update`/`delete`); значения `Changes\GrantFilter` (вид, субъект, контекст, роль, право, состояние `active`/`expired`/`orphaned`/`any`, `limit` 1..500, курсор) и `Changes\GrantPage`. Чужой id не раскрывается: `find` → null, `update`/`revokeMany` → `StaleSelectionException` всей операции до pipes. Курсор keyset связан с partition, фильтром и сборкой кода. Состояние `orphaned` — выдачи удалённой или неназначаемой роли, незарегистрированного или непривязанного контекста, отсутствующего или PolicyOnly exact-права и паттерна без покрытия: они не дают прав и удаляются по сохранённому scope (в том числе permission-выдачи у `rolesOnly()`-писателя). Явная миграция прежнего ключа роли (`#[FormerKeys]`) — внутренняя операция `ChangeType::MigrateRoleGrant` через пайплайн: строка сохраняет id, scope, субъект, origin, срок и поля, коллизия оставляет текущую выдачу с более поздним сроком; dry-run без записи. `RoleGrantUpdated` получил `previousRole` (`previous_role` в журнале).

- **core:** Схема панели для интерфейсов: неизменяемые значения `Schema\{PanelSchema, PermissionSchema, RoleSchema, FieldSchema, AssignmentScopeBindingSchema, AssignmentScopeTypeSchema, TenantTypeSchema, SubjectTypeSchema}` с `toArray()`/`jsonSerialize()` только из скаляров (без моделей, closures, сервисов и параметров источников/плагинов). Схема строится для пары (панель, tenant): статичный каталог и dynamic-права только выбранного tenant (tenant-панель без tenant их не собирает); у права — authority, источник-владелец, `decidedBy` (`policy:Class@method` / `gate:ability`), источники, которые могут его дать (`[]` для PolicyOnly), типы контекстов; у PHP-роли — класс, `editable=false`, выдача вручную/автоматически, привязки областей с классами фильтров; поля выдач моделей writer и плагинов с происхождением; типы tenants, областей и субъектов с auth guard и директориями. `Contracts\Sources\SourceDescription` получает `label` и `fields` (поля моделей `DatabaseSource`). Публичный `PanelAccess::schema()` — позже.

- **core:** Каталоги поиска по умолчанию: `Directories\ModelSubjectDirectory` (по моделям `for(model:)` панели: ключ и колонки статического `azguardSearchColumns()` модели как bound LIKE, label из `azguardLabel()` либо ключ), `QueryScopeDirectory` (для `QueryableAssignmentScopeDefinition`) и `ModelTenantDirectory` (по `ModelTenantDefinition`, членство не решает); директорию из `for(..., directory:)` и `BaseAssignmentScope::directory()` выбирает внутренний `DirectoryResolver::for(Panel, Container)`. Поиск contexts в фазе Assignment применяет common и role-binding фильтры общим predicate engine `Scopes` (новые `EligibilityBuilder::structural()` и `Scopes\RoleBindings`, их же использует `ScopeEligibility`) с target user, BaseRole, proposed и actor из `LookupContext`, а не текущего админа, и access adapter типа до LIMIT; без выбранного target результат пуст до фильтров, роль без привязки к типу даёт пустой список. Владелец кандидата — `tenantOf()` (любой callback): стабильный key-scan чанками, чужой tenant не расходует limit, исчерпанный бюджет `maxScan` без limit и без конца кандидатов — `DirectoryScanLimitException` (`directory_scan_limit`), а не неполный список. `describe()` — одна структурная загрузка: чужой tenant, другой тип или отсутствующая запись дают `null` без label в любой фазе; Inspection/Revocation пропускают только eligibility. На SQLite wildcard-символы LIKE из термина отбрасываются (нет escape по умолчанию), на остальных движках искомый текст буквальный.

- **core:** События изменений после commit и журнал `azguard/audit` (event, schema): публичный каталог `Events\{AccessEvent, EventType, PermissionCreated, PermissionUpdated, PermissionDeleted, RoleGranted, RoleRevoked, RoleGrantUpdated, PermissionGranted, PermissionRevoked, PermissionGrantUpdated, GrantExpired, PanelStateTouched, AccessDecided}` — 12 типов (`permission.created`, `role.granted`, `grant.expired`, `panel.touched`, `access.decided`, …), payload только скаляры и массивы. `ChangePipeline` публикует по одному событию на `ChangeEffect` после корневого commit: откат, повтор транзакции и `Unchanged` событий не дают, исключение слушателя репортится и не мешает остальным (раньше событий не было вовсе, а повторная выдача 0.3 отправляла событие внутри транзакции). `ChangeEffect` получил `expired`, `previousVersion`, `reason`; `ChangeType::TouchPanel` и внутренние `ChangePipeline::touch()` (→ `PanelStateTouched`) и `pruneExpired()` (→ `GrantExpired`). `AccessDecided` публикует `Authorizer` только при `trace_decisions` панели; внутри транзакции пакета — после её commit. Схема хранилища: таблица `{prefix}audit_log` (её создаёт `StorageSchema`, версия схемы хранилища прежняя); плагин `Plugins\Audit\AuditPlugin` (`azguard/audit`, выключен по умолчанию) пишет в неё строку на effect в той же транзакции через `Changes\ChangeJournal`.

- **core:** Динамические права пишутся через тот же пайплайн изменений: `ChangeType::{CreatePermission, UpdatePermission, DeletePermission}`, значения `Changes\{PermissionDetails, PermissionRecord}` (`ChangeResult` и `ChangeEffect` принимают `PermissionRecord`; `ChangeResult::removedGrantIds()`), `ChangePipeline::{createPermission, updatePermission, deletePermission}` и запись в `DatabaseSource::apply()`. Создание требует `dynamicPermissions()` (иначе `PanelNotWritableException`; писатель `rolesOnly()` с этим флагом управляет правами, но не выдаёт их напрямую), имя проверяется каталогом под lock: статичное имя — `DuplicatePermissionException`, префикс панели — `PrefixConflictException`, повтор — `DuplicatePermissionException`; update/delete несуществующего, статичного или чужого tenant имени — `UnknownPermissionException`. Право принадлежит tenant, всегда в режиме Grants, не получает subject/роль/срок, а поля отклоняются целиком (схемы полей у действия нет); `PermissionDetails` — полная замена label/group/description. Удаление одной mutation сначала отзывает каждую exact-выдачу этого имени в tenant отдельным `RevokePermission` через pipes (все origin и контексты), затем удаляет строку права; pattern-выдачи, другие имена и другой tenant не затрагиваются, отмена любого отзыва откатывает всё, выдача, начатая раньше удаления, не оставляет сироту, начатая позже — `UnknownPermissionException` (инвариант C9; engine-гонки на PostgreSQL/MySQL/MariaDB).

- **core:** Точная видимость (`visibleTo()`) компилирует `predicate()` каждого ограничения с `FiltersAccessQueries` и не вызывает его `appliesTo()`: раньше список вызывал `appliesTo()` без ресурса и при `false` пропускал ограничение, поэтому `appliesTo`, зависящий от ресурса, давал список шире скалярного `decide()` (лишние строки при нарушенном обязательном ограничении). Применимость адаптерного ограничения кодируется в `predicate()` (`AccessPredicate::pass()`); ограничение без адаптера по-прежнему пропускается при `appliesTo()=false`, его ответ не должен зависеть от ресурса (docblock `Restriction`, `FiltersAccessQueries`; аудит P4, A05, D22).

- **core:** Право в режиме Grants без квалифицированного вклада даёт `Deny(NotGranted)` до вызова host-политики во всех путях (`decide`, `decideMany`, `explain`; шаг `policy` трассы = `skipped`): раньше политика исполнялась для субъекта без права, а её `false`, `Response::deny()` или исключение подменяли причину на `Policy`/`PolicyError`. Veto и `PolicyError` остаются для субъекта с квалифицированным вкладом; режим Policy не изменён (аудит P4, A04, D21).

- **core:** Ошибка построения источника на пути динамического каталога (`decide()` для права, которого нет в статическом каталоге) даёт `Deny(SourceError)`, а не исключение из `decide()` (аудит P4, A08).

- **core:** `decideMany()` кэширует внешнюю eligibility и нативный вердикт по полной идентичности вклада (класс, роль, область, источник, происхождение, паттерн, срок, поля) вместо `json_encode` value-объектов: раньше вердикт одного вклада переиспользовался для другого, и пакетный результат расходился со скалярным (в том числе ложный Allow). Ошибки конфигурации (`DefinitionException`, `UnknownPermissionException`, `InvalidConfigurationException` кроме `authority_transaction`) пробрасываются из `decideMany()` как из `decide()`, а не превращаются в `source_error` (аудит P4, A03, A07).

- **core:** Прямой `Grant` с ключом роли `#[SuperAdmin]` засчитывается только своим паттерном и не освобождается от ограничений `exemptsSuperAdmin()`: раньше он давал Allow(SuperAdmin) на любое право панели в `decide()` и в точных списках (аудит P4, A02).

- **core:** Точная видимость (`visibleTo()`, `PredicateCompiler`) заключает WHERE хоста с raw-условием (`whereRaw` с `OR`) и WHERE дескриптора области в одну вложенную группу перед добавлением группы авторизации: раньше `x OR y` обходил авторизацию, список показывал и считал запрещённые строки, а гость (`subject: null`) видел строки. Не добавляйте верхнеуровневый `orWhere` после `visibleTo()` (аудит P4, A06).

- **core:** `Authorizer` зарегистрирован как scoped: после сброса scoped-экземпляров (следующий запрос Octane, следующее задание очереди) `decide`, `explain`, `decideMany`, Gate и `azguard:explain` читают текущие `CurrentContext`/`CurrentPanel`, а не контекст первого жизненного цикла — исправлено разрешение в чужом tenant/панели (аудит P4, A01).

- **core:** Пайплайн изменений назначений: значения `AzGuard\Changes\{Change, ChangeType, ChangeContext, ChangeResult, ChangeStatus, ChangeEffect, EffectKind, GrantRecord, GrantDetails}` и единственный путь записи — lock `panel_state` первым, план и чтения под lock на write-PDO активной mutation, pipes `changing` панели (provider → plugins → configure) с exactly-once продолжениями и результатом писателя, финальная проверка роли/права/tenant/контекста/срока/полей под lock и `StoresGrants::apply()` в `DatabaseSource`. Повтор без отличий — `Unchanged` без bump и эффекта; составные grant/revoke/sync/revoke по id — all-or-nothing в границах panel/tenant/context/origin; update по id сверяет fingerprint, включающий build. Отзыв orphan/expired/inactive выдач не требует текущей eligibility. `Panel::changing()`, неизменяемые `Panels\SubjectDescriptor` (model/guard/directory), `ScopeEligibility::assignment()` и общий guarded predicate engine в `Scopes\Query`; `AssignmentScopeRuntime` получает validated `proposed`, actor в нём и в `LookupContext` nullable. Десять исключений изменений (`UnknownRoleException`, `PermissionNotGrantableException`, `StaleSelectionException` и др.); регрессия P13 закрыта.

- Исправлена приёмка P4: тест Trace учитывает `outcome`/`detail` и проверяет отключённую трассу; owner P10b синхронизирован с P4.20. Стандартный Pint исключает raw evidence в `plans`, PHPStan анализирует `ContextAware` вместе с fixture-потребителем `VisibilityProject` при прежнем уровне 8.

- **core:** Exact visibility reuses schema observations within one compilation and checks fixed tenant membership once per operation. CRM literal lists/count/pages, 100 seeded scalar/query parity cases, 10k-client SQL budgets and real PostgreSQL/MySQL EXPLAIN on 100k clients verify the complete visibility contract.

- **core:** `Visibility::visibleTo()` and `Authorizer::visibleTo()` compose exact owner, context, before, policy, restriction and complete assignment witness predicates before count/pagination. Explicit `ContextAware` relation mappings preserve resource/context identity; unsupported components and unstable sources fail closed with safe diagnostics. Fenced materialized selections support separate assignment connections with a 1000-ref budget. `bounded()` checks the complete bounded host universe with one clock and honest permitted totals.

- **core:** Exact query SPI `FiltersAccessQueries` and pure `AccessPredicate` describe bound comparisons, logical/EXISTS trees, policy and before partitions, and complete contribution witnesses. `PredicateCompiler` validates model columns and typed relations, normalizes SQL NULL, and adds an atomic nested AND constraint; unsupported trees and unsafe host roots fail before resource execution.

- **core:** `Authorizer::explain()` and readonly `Kernel\Decision\Explanation` capture one evaluation, contribution origins/qualification, skipped stages and scalar scope/state; nested secrets and matching message values are redacted. `azguard:explain` prints JSON or a step table without publishing cold diagnostic cache reads (P4.10).
- **core:** Authoritative `Laravel\Gate\GateBridge` uses the common resolver/pipeline for registered full/prefix names, local static/dynamic names and indexed model abilities; owned denial is final, ambiguous pairs deny, foreign abilities fall through. `toGateResult()` preserves policy Response details. P03/P09, Blade and 200 seeded P9 parity cover current surfaces; earlier host Gate::before callbacks remain an integration constraint (P4.11).

- Ядро 1.0: `Authorizer::decideMany()` сохраняет скалярные решения и порядок запросов, читает назначения пачками до 100 контекстов под единым fence группы и загружает host-модели/eligibility пакетно. Один `now`, отдельные вызовы политик/условий/ограничений, typed state map; CRM 1k/10k и конкурентный revoke квалифицированы. `resolve()` использует общий PanelResolver, конфликт панелей отклоняется.

- Квалификация кэша ядра 1.0: независимые процессы проверяют revoke/fence и lock-first протокол на PostgreSQL/MySQL/MariaDB, настоящий Redis — expiry/build/restore, PostgreSQL streaming replica — окно Default и отказ Primary. Воспроизводимый benchmark записывает cold/warm p95/p99 и бюджеты SQL. Fingerprint DB authority теперь включает deployment build id, чтобы новый build не использовал прежние raw cache entries.

- CRM-приёмка ядра 1.0: реальный стенд с двумя панелями, tenant/project, PHP-ролями, DB/relation-назначениями и литеральными ожиданиями проверяет границы доступа, policy/grant modes, typed filters и host freshness; реестр R01–R68 фиксирует проверенные срезы и будущие поверхности.

- Квалификация scoped sources ядра 1.0: итоговые решения Database/Folder/Relation, изоляция tenant/context, поля выдачи и dynamic fence проверены на SQLite, PostgreSQL, MySQL и MariaDB; регрессия P08 покрывает одинаковые права DB-роли глобально и в точной области назначения.
- Квалификация ядра 1.0: 13 генеративных свойств движка и scope проверяются на 200 воспроизводимых seeds каждое, включая изоляцию панелей/тенантов, request cache, fail-closed ошибки и условия одной выдачи.

- Ядро 1.0: общие фильтры контекста объединяются через AND до authority, ролевые фильтры проверяют отдельную выдачу. Native Builder защищает structural identity и owner; внешние области поддерживают типизированные access adapters без Eloquent. Runtime-входы вычисляются заново, panel/context изолированы между Fiber.

- Ядро 1.0: raw contributions кэшируются по состоянию, субъекту и точной области с изоляцией источника и read authority. Request/check refresh и абсолютные сроки действуют также в request memo; условия, политики и ограничения вычисляются при каждой проверке.

- Ядро 1.0: `Authorizer::isSuperAdmin()` квалифицирует роль в выбранной панели и области с учётом сроков, условий и фильтров. Глобальные роли применяются к tenant только через явный `allowGlobalRoles`; освобождение от restriction зависит от квалифицированной роли суперадмина и сохраняется при дополнительных обычных выдачах.

- Ядро 1.0: панели независимо задают tenant и assignment scope; проверка прав учитывает владельца ресурса, членство и режимы `inherit`/`isolated`/`required`/`none`. Контекст хранится отдельно для каждой панели и восстанавливается после вложенных вызовов и исключений.

- Ядро 1.0: `RelationSource` читает роли из связей моделей хоста и возвращает raw witnesses для выбора сущностей. Источник ограничивает чтение точным субъектом и tenant/context, поддерживает статичную роль, pivot-атрибут и callback; конфигурация проверяется при сборке панели.

- Ядро 1.0: opt-in dynamic definitions читаются для точного tenant поверх статичного каталога. Prepare и authority используют один fence на закреплённом read PDO; retry отбрасывает overlay и весь набор выдач. Статический PolicyOnly не читает dynamic/assignment storage.

- Ядро 1.0: `DatabaseSource` читает точные назначения и выбранные поля выдач, поддерживает `rolesOnly`, builder и raw witnesses для query prefilter. Обе capability объединены fence с тремя попытками на закреплённом Primary/Default PDO; чужая транзакция даёт отказ только при потреблении DB authority. `Storage::own()` повторно использует каноническую пару connection/prefix с проверкой host keys.

- Ядро 1.0: mode-aware политики сохраняют native `before`, DI ресурса и поля Laravel `Response`; `GateSource::map()` вызывает внешнюю ability без глобальных Gate hooks. Типизированные PHP/Gate bindings и индекс model/ability сохраняются в кэше каталога.

- Ядро 1.0: `FolderSource` выдаёт автоматические роли и точные `GrantedToAll`-права; прежние, удалённые и невыдаваемые ключи ролей дают ноль authority с диагностикой. Исключение для `NotGrantable` требует фактического исполнения автоматической роли встроенным источником.

- Ядро 1.0: добавлен внутренний пайплайн `Authorizer::decide()` с раздельными PolicyOnly/RequiresGrant, условиями одной выдачи, ограничениями, typed хуками, безопасным policy DI и защитой от рекурсии.

### Fixed

- **core:** Поля Grant и RoleContribution рекурсивно отделяются от внешних PHP-ссылок, сохраняя неизменяемые plain-data значения после проверки. API manifest отражает фактические значения default-констант.
- **core:** Enum cases из статичных custom sources участвуют в выборе панели на live и cached путях. Равные permission definitions и одинаковый role class из независимых plugin/provider origins дают collision с id вкладов; повторы внутри одного origin сохраняют первого владельца (owning repair P2.5, R2/R3).
- **core:** Явные policy bindings из FolderSource и custom ProvidesPolicies выбирают единственный public nonstatic метод с подходящим `#[Decides]`. Неверный explicit method, отсутствие или неоднозначность атрибута дают `DefinitionException`; найденный метод сохраняется в каталоге и после кэша (owning repair P2.8, R1).

### Added

- **core:** Модели выдач и динамических прав с проверкой таблицы/подключения хранилища и final identity-геттерами; свои поля колонками или в `meta`, поля панели и плагинов с происхождением, серверная валидация и выборка `decisionFields`, `defaults.models`.
- **core:** Прямые записи экземпляров и массовые записи Eloquent запрещены для базовых и своих моделей во всех окружениях, включая quiet/eventless paths; внутренний builder и arch-правила поддерживают запись через `Storage::mutate()`.

- **core:** Публичный `StorageSchema` создаёт схему выдач, динамических прав и состояния на SQLite/PostgreSQL/MySQL/MariaDB; миграция default загружается ядром, `azguard:storage:migration` генерирует миграцию настроенного хранилища. `storage_state` сверяется при первом обращении; DDL-снимки и ограничения проверены на четырёх СУБД, VARBINARY сохраняет точную идентичность на MySQL/MariaDB (PLAN2.P3.2, D13).

- **core:** Именованные хранилища, канон host keys и `Storage::mutate()` с блокировками состояния панели, ограниченными повторами, одной версией на изменение и публикацией после внешнего commit; проверки конкурентности PostgreSQL/MySQL/SQLite (PLAN2.P3.1).

- **core:** Кадры областей и directories: `Scopes\AssignmentScopeRuntime` (панель, область, субъект, пользователь, роль или null, выдача, актор, момент, фаза; кадр не читает Auth и контейнер), `Directories\LookupContext` (актор отдельно от цели, `proposed` — поля назначения), `Directories\{TenantOption,AssignmentScopeOption,SubjectOption}`. SPI `Contracts\Scopes\{AssignmentScopeFilter,AssignmentScopeAccessAdapter,ConfigurableAssignmentScopeDefinition,TenantDirectory,AssignmentScopeDirectory}` и `Contracts\Subjects\SubjectDirectory`. `Scopes\AssignmentScopeSettings` хранит фильтры, подпись и класс directory и не хранит Model, Request и Builder. `Scopes\BaseAssignmentScope`: abstract `type()`/`query()`/`tenantOf()`, `model()` из `query()->getModel()`, `resolve()` делает один `whereKey()->first()`; global и чужой тип — `InvalidAssignmentScopeException`, отсутствие записи — null, tenant берётся из той же записи. `filter()`/`label()`/`directory()` возвращают копию; строка фильтра — класс `AssignmentScopeFilter`, строка directory — класс `AssignmentScopeDirectory`, иначе `DefinitionException`. Универсальной `make()` нет. Зона `AzGuard\Directories` во внешних зонах и не зависит от Storage, Changes и Authorization. Применение фильтров к запросу, разрешение class-string контейнером и membership остаются в P4.6 (план 2026.10.01-№1-AZGUARD-V1 P2.9, D6).
- **core:** Папка панели как источник `Sources\Folder\FolderSource` (`make()`, `folders(...)`, `id()` = `folder`, `isDynamic()` = false) и `PanelDiscovery`. Атрибуты `Permissions\{Resource,Describe,RequiresGrant,PolicyOnly,GrantedToAll}` и `Policies\{PolicyFor,Decides}`. `PanelBuilder::discover(path, namespace)` и `policies([...])`. Встроенный `FolderSource` — первый источник каждой панели; enum из `permissions([...])`, роли из `roles([...])` и привязки из `policies([...])` добавляются к найденным в папке. Пара политик — один enum и одна политика одной группы одного root, иначе `#[PolicyFor]`; метод участвует только с `#[Decides]`, имя метода свободно. Режим authority — `#[RequiresGrant]` или `#[PolicyOnly]` на case (перекрывает enum) либо на enum; оба или ни одного — `DefinitionException`. `#[GrantedToAll]` записывается в метаданные discovery. Корни `permissions` и `policies` не совпадают и не вложены. `Shared/` не панель: `#[AsSource]` из `Shared/Sources` и `Sources/` панелей регистрируется в `SourceManager`. Секция конфига `discovery`. Снимок discovery в `azguard:catalog:cache`; при тех же корнях и хешах файлов классы не парсятся заново. Roots, имена папок и sha256 файлов входят в отпечаток. Найденные enum пополняют индекс `PanelResolver`. Префикса ключей у плагина нет (D10). Arch-зона `AzGuard\Permissions` (план 2026.10.01-№1-AZGUARD-V1 P2.8, D56, D72, D73).
- **core:** Фабрика источников `Sources\SourceManager` (`extend`, `make(name, panel)` — новый объект на каждый вызов, без кэша `driver()`), `#[AsSource]`, регистрация класса. Контракты `ProvidesGrants`, `ProvidesRoleGrants`, `StoresGrants` (только `transaction()`), `DescribesSchema`, enum `Volatility`, `SourceDescription` и интерфейс `EvaluationContext` без реализации. Имя в `permissions([...])` собирается фабрикой на своём месте; объект остаётся объектом рецепта. Повтор `id()`, неизвестное имя и больше одного писателя (`WriterConflictException`, `writer_conflict`) видны при сборке. Именованный источник на исполнении создаётся заново на каждую панель и на каждую область (scoped, сбрасывается между запросами). `Panel::sources()`, `isWritable()`, `writer()`. `AzGuard::sources()`. Секция конфига `sources` и `AzGuardConfig::source()`. Имена и классы именованных источников входят в отпечаток панели в порядке сборки (план 2026.10.01-№1-AZGUARD-V1 P2.7, D8).
- **core:** Фасад панелей `AzGuard\Facades\AzGuard` и корень `AzGuard\AzGuardManager` (не хранит панели и не выбирает панель): `registerPanel`, `configurePanel`, `configurePanels`, `panels`, `currentPanel`. Контейнер отдаёт один экземпляр по классу и по имени `azguard`. Модуль вызывает фасад из `register()` до заморозки и берёт id панели из своего конфига; `configurePanel` пишет слой `provider`, `configurePanels` — `configure`. Незарегистрированная панель — `UnknownPanelException` (`unknown_panel`) при сборке, вызов после заморозки — `RegistryFrozenException`. Плагин модуля приносит источник-объект с именами, которые задал сам модуль (`blog.posts.edit`, роль `blog-editor`, id плагина без префикса ключей); второй модуль со своим именем не сталкивается, то же имя — `DuplicatePermissionException` с id обоих плагинов. Своя панель модуля регистрируется через `registerPanel`. Enum модуля на двух панелях без явной панели — `AmbiguousPanelException`, enum вне панелей — `UnknownPermissionException` (план 2026.10.01-№1-AZGUARD-V1 P2.6, D10).
- **core:** Каталог панели из источников: SPI `Contracts\Sources\ProvidesPermissions` (`permissions(Panel, ?TenantRef)`, `isDynamic()`), `ProvidesRoles` (`roles(Panel)`), `ProvidesPolicies` (`policies(Panel)`); `Catalog\PermissionDefinition` (`local`, `authority`, `label`, `group`, `description`, `case`, `resourceModel`; имя проверяется грамматикой); `Policies\PolicyBinding::for(case|имя, Policy::class)`; `Contracts\Catalog\PermissionCatalog` (`panel()`, `has()`, `find()`, `get()`, `keyOf(case)`, `all()`) — все поиски по хеш-индексам, без запросов. Статичный каталог собирается при загрузке (`tenant = null`, динамические источники не читаются): одинаковые определения одного имени из двух источников — один элемент, любое различие (режим authority, подпись, enum case) — `DuplicatePermissionException` (`duplicate_permission`) с id источников и плагинов; имена плагина попадают в каталог как объявлены. Роли источников компилируются в ключи, прежние ключи, права (enum case, локальное имя, шаблон) и области: повтор ключа или прежнего ключа между ролями — `DuplicateRoleException` (`duplicate_role`), право режима Policy в роли — `DefinitionException`, `scopeRequired()` без `scopes()` — `DefinitionException`, переопределённые `key()`/`formerKeys()` проверяются как атрибуты. Привязки политик: две политики у права — `DuplicatePolicyBindingException` (`duplicate_policy_binding`), право режима Policy без привязки — `InvalidPolicyStructureException` (`invalid_policy_structure`). Повтор `id()` источника — `DefinitionException`, имя источника — `UnknownSourceException` (`unknown_source`) до регистрации имён. Динамический overlay `withDynamic()` принимает только права режима Grants, не повторяющие статичные имена и не начинающиеся с префикса панели. Команды `azguard:catalog:cache` и `azguard:catalog:clear`, ключ конфига `catalog.cache_path` (по умолчанию `bootstrap/cache/azguard.php`): файл хранит только скаляры и используется панелью, лишь если совпали id сборки и отпечаток её рецепта (план 2026.10.01-№1-AZGUARD-V1 P2.5).
- **core:** Плагины панели: SPI `Contracts\Plugins\Plugin` (`id()`, `register(PanelBuilder, PluginContext)`, `boot(Panel, PluginContext)`) и `DependsOnPlugins` (`requires()`), база `Plugins\BasePlugin` (только жизненный цикл: без `make()`, `options()`, `withOptions()` и без префикса ключей — плагин приносит имена прав и ролей как объявлены), `Plugins\PluginContext` (`panelId()`, `pluginId()`, `buildId()`, `dependencies()`). `PanelBuilder::plugins([...])` принимает объекты и классы плагинов (класс создаёт контейнер), `withoutPlugins([...])` не пускает на панель плагин, подключённый `configurePanels()`; `Panel::pluginIds()`. Каждая панель работает со своей копией плагина; порядок: плагины провайдера, плагины `configurePanels()`, затем плагины, подключённые плагинами. Всё, что плагин добавил в `register()`, записано с происхождением `plugin:<id>`; после фазы `register` панель запечатана, `boot()` вызывается после заморозки реестра, изменение панели из `boot()` — `RegistryFrozenException`. Отсутствующая зависимость — `PluginDependencyMissingException` (`plugin_dependency_missing`), два плагина с одним id — `PluginConflictException`, id вне формы `name` или `vendor/name` — `DefinitionException`. Плагины и их порядок входят в отпечаток панели. Ключ конфига `catalog.build_id` (env `AZGUARD_BUILD_ID`) и `AzGuardConfig::buildId()`: без явного значения id сборки выводится из файлов провайдеров панелей (план 2026.10.01-№1-AZGUARD-V1 P2.4, D10).

- **core:** Настройки панели: `PanelBuilder::cache(store, ttl, generation)`, `gate(GateMode)`, `consistency(Reads, StateRefresh)`; enum `Panels\GateMode`, `Reads`, `StateRefresh`; `Panel::settings()` возвращает `PanelSettings` с итоговыми значениями и происхождением каждого (`provider`, `plugin:<id>`, `configure`, `default`). Порядок: провайдер панели → плагины → `configurePanels()` → секция `defaults` в `config/azguard.php` (`resource_prefix`, `gate.mode`, `cache.*`, `consistency.*`, `trace_decisions`); два плагина с разными значениями одной настройки без решения провайдера — `PluginConflictException`. Значение вне enum или диапазона — `InvalidConfigurationException` с кодом `invalid_configuration.enum`, постоянный store без ttl — `invalid_configuration.cache_ttl`, неизвестный ключ `defaults` — ошибка загрузки. Списки (роли, хуки, резолверы) складываются из всех слоёв, `presentation` сливается по ключам с тем же приоритетом (план 2026.10.01-№1-AZGUARD-V1 P2.3).
- **core:** Единое правило выбора панели `AzGuard\Panels\PanelResolver` (внутренний класс): все явные сигналы — аргумент панели, полное имя `panel:local`, имя с префиксом панели, enum прав, подключённый к панелям, — собираются и обязаны указывать одну панель, иначе `ConflictingPanelException`; затем панель запроса, принимающая субъект, затем панель модели (`azguardDefaultPanel()`, `default()`, единственная панель), иначе `PanelNotResolvedException` с подсказкой. Enum нескольких панелей без явной панели — `AmbiguousPanelException`, enum вне панелей — `UnknownPermissionException`. Префикс панели — один сегмент, уникальный среди панелей (`PrefixConflictException` при сборке); имя с префиксом и локальное имя дают один `PermissionKey`. Arch-правило: текущую панель и панель модели читает только resolver. Регрессии P01c, P05, P09 закрыты на уровне resolver (план 2026.10.01-№1-AZGUARD-V1 P2.2).
- **core:** Панели `AzGuard\Panels`: `PanelProvider` (`static getId()`, `panel(PanelBuilder)`, регистрирует себя в реестре), `PanelBuilder` (идентичность, `for(model, guard:)`, `resourcePrefix`, маршрутные настройки, `permissions([...])` для string-backed enum, объектов `Source` и имён источников, `roles`, `presentation`, аддитивные списки хуков и резолверов; каждая настройка записывается вместе с происхождением `provider`/`plugin`/`configure`), неизменяемый `Panel` (`id`, `label`, `isDefault`, `prefix`, `subjectModels`, `accepts`). Реестр `Contracts\Panels\PanelRegistry`: `register`, `replace`, `configure`, `configureAll` до заморозки; панели собираются на `booted`, после чего любое изменение — `RegistryFrozenException`, а чтение до сборки — `DefinitionException`. Scoped `CurrentPanel`, контракт `Contracts\Sources\Source`, исключения `DuplicatePanelException`, `UnknownPanelException`, `RegistryFrozenException`, `DefaultPanelConflictException`, `SubjectNotAcceptedException`, `InvalidConfigurationException`. Конфиг `config/azguard.php` с `panels.providers` и типизированный `Configuration\AzGuardConfig` (неизвестный ключ секции — ошибка загрузки) (план 2026.10.01-№1-AZGUARD-V1 P2.1).
- **core:** Смысл authority modes на `PermissionAuthority`: `allows(?bool $policy, bool $qualifiedContribution)` (Policy — только `true` разрешает; Grants — квалифицированное назначение и политика не `false`), `readsAssignments()`, `acceptsAssignments()`, `superAdminIsAuthority()`. Кодовые роли `AzGuard\Roles`: `BaseRole` с атрибутами `Role`/`SuperAdmin`/`NotGrantable`/`FormerKeys` (атрибут и метод равнозначны, ключ обязателен и проверяется грамматикой), `GrantedAutomatically`, готовая `SuperAdminRole` с переводом `azguard::roles.superadmin` (en/ru). Структурные SPI областей и субъектов `AzGuard\Contracts\Scopes` (`AssignmentScopeDefinition`, `QueryableAssignmentScopeDefinition`, `ResolvedAssignmentScope`, резолверы и membership tenant/области) и `AzGuard\Contracts\Subjects\SubjectResolver`; `AzGuard\Scopes\AssignmentScopePhase` (план 2026.10.01-№1-AZGUARD-V1 P1.6).
- **repo:** Блокирующие arch-правила зон ядра (`tests/Arch/ZonesArchTest.php`), проверка конвенций исходников (коды задач в комментариях, `config('azguard…')` только в `Configuration\`, теги `@api`/`@spi` у `Contracts\`) и машинный манифест публичного API `packages/*/api-manifest.json` с генератором `bin/api-manifest.php` (`composer api:manifest`, `composer api:check`; проверка входит в `composer test`) (план 2026.10.01-№1-AZGUARD-V1 P1.4).
- **core:** Значения решения `AzGuard\Kernel\Decision`: `AccessRequest` с явными tenant/context/resource, `Decision` с проверяемым инвариантом effect/reason, `DecisionSet`, `Grant` и `RoleContribution` с владельцем (origin), сроком и полями одной выдачи, `RestrictionResult`, `BeforeResult`, `PermissionAuthority`, раздельные `CodeStateToken`/`StateToken`; `AzGuard\Kernel\Permissions\PermissionSet` (план 2026.10.01-№1-AZGUARD-V1 P1.3).
- **core:** Идентичность `AzGuard\Kernel\Identity`: readonly `PermissionKey`, `PermissionPattern`, `RoleKey`, `SubjectRef`, `TenantRef`, `AssignmentScopeRef`, `AccessScope`, `AnyAssignmentScope`, `ActorRef` с проверкой при создании и `IdentityCodec` (канон id, alias типа, tagged-кодирование ссылок, составной ключ JSON-массивом с версией кодека и digest). Ключ области однозначен, ключи кэша разных `(panel, subject, contexts)` не совпадают (план 2026.10.01-№1-AZGUARD-V1 P1.1).
- **core:** Грамматика `AzGuard\Kernel\Grammar`: `PermissionGrammar` (сегмент, локальный ключ ≥ 2 сегментов и ≤ 255 байт, полный ключ `panel:local`, шаблон с `*`/`**` только последним сегментом, id панели и ключ роли `^[a-z0-9][a-z0-9-]{0,63}$`) и `PatternMatcher::covers()` по массивам сегментов; голые `*`/`**` отклоняются. База исключений `AzGuardException` с `code()` и ветками D37, `InvalidIdentityException` и её грамматические наследники (план 2026.10.01-№1-AZGUARD-V1 P1.2).
- **docs:** ADR 0001 «Ecosystem conventions» (`docs/adr`, EN/RU): общие инженерные правила экосистемы `axiomasoft` и предметный словарь AzGuard (план 2026.10.01-№1-AZGUARD-V1 P0.1).
- **core:** Каркас 1.0: пустые пакеты `axiomasoft/azguard` (`packages/core`) и `axiomasoft/azguard-filament` (`packages/filament`) с service provider-ами, Pest/Testbench-набором `Arch`/`Unit`/`Feature`/`Regression`, PHPStan level 8 без baseline (план 2026.10.01-№1-AZGUARD-V1 P0.5).
- **docs:** Архитектурный аудит от 2026-09-29 дополнен самостоятельными находками, вариантами redesign и Research с первичными источниками и воспроизводимыми probes.
- **core:** `PermissionSet::validUntil()` / `withValidUntil()` carry an optional absolute grant deadline. Request and durable caches reject a hit at that instant and store a strict `{version, keys, valid_until}` envelope; a custom source without a deadline still follows the configured TTL (plan P1.2 / D3).
- **core:** Role-permission edits go through one transactional synchronizer. Filament edits only the rendered catalog keys and rejects a stale fingerprint; CLI add/remove stay one-key and `sync --panel` replaces that panel (plan P2.1 / D13).
- **core:** Official authorization mutations bump a global `az_guard_permission_state` revision on the same connection. Request, durable and scoped-role caches key by that revision and bypass all reusable tiers inside a transaction (plan P2.2 / D13).
- **core:** Permission cache keys include an explicit `cache.generation`. `guard:cache-reset` advances the DB revision and clears local request state without flushing the configured store; cache get/put/lock failures recompute from sources instead of returning a stale allow (plan P2.3 / D13).
- **core:** Opt-in `az-guard.require_permission_attributes` (default off): `azguard.check` raises `MissingPermissionAttributeException` when a controller action has neither `#[CheckPermission]` nor `#[SkipGuardCheck]`; `guard:doctor` warns in legacy mode and errors when the flag is on (plan P4.2 / D7).
- **core:** `guard:sync-roles` persists panel-scoped code roles as `{panelId}:{getName()}` with preflight/dry-run classification, in-place legacy rename, and fail-closed collisions; `guard:doctor` errors on stale/invalid `class_name` (plan P5.1 / D8).
- **core:** `make:guard-panel` and new `make:guard-domain` share a safe scaffold generator: validated `--model` / `--actor` FQCNs, per-file conflict detection, idempotent reruns, recognized provider/config registration only, and `guard:doctor` generated scaffold errors (plan P5.2 / D8).

### Changed

- Ядро 1.0: собственные mutations и совместный authority root читают tentative данные с write PDO без публикации в кэш. Marker связан с root attempt и Fiber; rollback/retry снимает его, touch инвалидирует request contributions только после root commit.

- **repo:** Arch-правило `StoresGrants` разрешает тип в `Panels\Panel` и `Sources\PanelSources` (признак писателя и тип `writer()`); вызов `transaction()` там по-прежнему запрещён. Зона `AzGuard\Attributes` добавлена во внешние зоны (план 2026.10.01-№1-AZGUARD-V1 P2.7).
- **repo:** Arch-правило выбора панели разрешает имя `CurrentPanel` в `AzGuard\AzGuardManager`: менеджер читает панель текущего запроса для `currentPanel()` и не выбирает панель (план 2026.10.01-№1-AZGUARD-V1 P2.6).
- **core:** Неквалифицированная Gate ability с точкой принадлежит панели-кандидату (панель запроса или модели), только если она есть в каталоге этой панели; иначе ability чужая. Префикс панели не может совпадать с первым сегментом статичного имени права любой панели, в том числе из плагина (`PrefixConflictException`). Каталог входит в отпечаток панели. Arch-правило: `Catalog` не зависит от `Authorization` и `Laravel` (план 2026.10.01-№1-AZGUARD-V1 P2.5).
- **core:** `InvalidConfigurationException::code()` уточняет проверку после точки (`invalid_configuration.enum`, `invalid_configuration.cache_ttl`); без уточнения код прежний. Итоговые настройки панели входят в её отпечаток (план 2026.10.01-№1-AZGUARD-V1 P2.3).
- **core:** `PanelRegistry::replace()` применяется при сборке панелей: порядок `register()` и `replace()` между провайдерами не важен, модуль может заменить панель из конфига в своём `register()`; замена id без регистрации — `UnknownPanelException` при сборке (план 2026.10.01-№1-AZGUARD-V1 P2.2, D9).
- **core:** `DefinitionException` перестал быть abstract (код `definition`): бросается там, где у ошибки описания нет более узкого класса — `id()` builder'а не совпал с `getId()`, не-`Model` в `for()`, неверный элемент `permissions([...])` или `roles([...])` (план 2026.10.01-№1-AZGUARD-V1 P2.1).
- **repo:** Arch-правило зоны `AzGuard\Configuration`: зависит только от Kernel, Exceptions и фреймворка; правило `Panels ↛ Storage, Changes` получило код и стало проверяющим (план 2026.10.01-№1-AZGUARD-V1 P2.1).
- **core:** Нарушения инвариантов значений решения бросают исключения иерархии `AzGuardException` вместо SPL `InvalidArgumentException`: новые `ConsistencyException` (`consistency`; `Decision`, `DecisionSet`) и `InvalidSourceContributionException` (`invalid_source_contribution`; `Grant`, `RoleContribution`, `RestrictionResult`), токены состояния — `InvalidIdentityException` (план 2026.10.01-№1-AZGUARD-V1 P1.8).
- **repo:** Arch-правило «no debug calls» стало token-сканом всех `packages/*/src` (прежнее Pest-правило не могло покраснеть); обход исходников и поиск вызовов глобальных функций — одна реализация `AzGuard\Tests\Arch\SourceScan` (план 2026.10.01-№1-AZGUARD-V1 P1.8).
- **repo:** Метаданные распространения переведены на `axiomasoft/*`: пакеты `axiomasoft/azguard` и `axiomasoft/azguard-filament` (`self.version`), репозиторий `github.com/axiomasoft/azguard`, линия `1.0.x-dev`; split и release работают с двумя пакетами, `context` из них убран (план 2026.10.01-№1-AZGUARD-V1 P0.4).
- **repo:** Код и тесты 0.3 заморожены в `legacy/0.3/` (только справочник, вне autoload, Pest, PHPStan, Pint, Rector и CI; удаляется в PLAN2.P6.9). Пакет `context` в сборке не воссоздаётся (план 2026.10.01-№1-AZGUARD-V1 P0.5).
- **core:** Grant-source exceptions now propagate and abort authorization; the previous `fail_on_source_exception=false` setting no longer skips a failed source. Remove that option from published config (plan P2.3 / D13).
- **filament:** Creating a direct grant with a past expiry now fails validation instead of converting it into a permanent grant; future expiry is stored as the exact absolute instant (plan P1.2 / D3).
- **core:** Scope assignment identity is an exact cross-engine unique index. Fresh `panel_id` is `VARCHAR(128)`. Recorded databases upgrade through `000006`, which rejects overlong panel ids and unsupported MySQL/MariaDB profiles before DDL. `roles.class_name` gains a nullable unique index. Context `000010` uses the configured table for both directions (plan P6.2 / D14).
- **core:** Fresh `000000` rolls base tables back in foreign-key order. `000005` dedupes assignment rows in SQL inside a transaction during a maintenance window; a failed insert keeps the original rows, and a failed index leaves a complete deduped set that a rerun can finish. A database that already recorded `000005` is unchanged (plan P6.1 / D14).
- **core:** Permission cache keys now use typed subject identity (persisted morph type + id) under the `azg:v2:` namespace, fixing cross-model ID collisions; grant/context invalidation and scoped-role request cache follow the same identity (plan P1.1 / D3).
- **core:** Configured `role` / `scope` / `direct_grant` / `role_permission` subclasses are validated and used on official read/write paths (relations, grant builder, database role source); split connections fail before mutation; `guard:doctor` reports bad model keys (plan P3.1 / D6).
- **filament:** Role and Direct Grant resources resolve their Eloquent model through `Config` (plan P3.2 / D6).
- **core:** Final panel resolution always rejects IDs longer than 128 characters; with `strict_panels`, unknown explicit/default/current/`app` fallback IDs throw even when the registry is empty (plan P4.2 / D7 / D14).
- **core:** `Gate::before` returns `null` for abilities AzGuard does not own (exact catalog key or registered dynamic definition), so ordinary Laravel policies run even for an AzGuard wildcard user (plan P4.2 / D7).
- **core:** Class/instance role lookup uses exact `class_name` with no `getName()` fallback; string lookup is the exact persisted name. Invalid non-null `class_name` throws `InvalidRoleClassException`. Built-in `SuperAdminRole` keeps reserved `super-admin` and refuses a same-name DB-only row (plan P5.1 / D8).
- **release:** `bin/release-preflight.sh` validates the candidate git tree before tags; `release.yml` runs it on the tagged commit. The post-tag `changelog.yml` writer is removed — version notes live in the candidate commit (plan P7.2 / D10).
- **release:** Split publication waits for release validation; docs publication waits for parity and PHP-floor checks (plan P7.2 / D10).
- **quality:** Line-coverage floor is 85% (measured 87.1%). Native Pest mutation floors are 99% per package (measured 100%).

### Fixed

- **authorization:** Filament catalog, resource Gate, and page/widget checks now use each linked panel's own plugin settings; an admin grant cannot authorize a tenant resource through the default panel.
- **cache:** Direct and context grant instance writes now commit with the database permission revision, so a cache epoch failure cannot leave a successful revoke behind a stale allow. Failed explicit epoch invalidation reports an error.
- **configuration:** Configured role-permission connections and model tables are checked consistently; invalid model configuration gives an actionable doctor result instead of a crash.
- **migrations:** PostgreSQL drops schema-qualified indexes correctly, and MySQL downgrade checks legacy collisions before any index DDL.

## [0.3.0] - 2026-07-22

### Bug Fixes

- **mutation:** Исключили fixture race из Pest baseline ([44baee9](44baee9c9453943da4c278e3d01633c1ab40ff98))
- **schema:** Заменить digest morph index (P4.13) ([cf85e16](cf85e1613b6c69a52412525f7eb72881450c5055))
- **plan:** Repair P4.10 escalation handoff ([d573451](d573451c354858b2fff7d3e8d578c2b6813e52d3))
- **schema:** Shorten morph index names (P4.12) ([7ce4934](7ce49345508938c5d0ec201abf694e35e8e0b691))
- **filament:** Сделать LIKE-escape переносимым (P4.9) ([77c118c](77c118cf67227ae9cd6412253a06c6b34905e73c))
- **schema:** Harden RBAC key collation (P4.7) ([4c4970f](4c4970faab0fe9fe6501393f94fbae9dedafbe3c))
- **plan:** Repair P4.8 escalation markers ([05991ae](05991aefe85b0170d89afe010b94fb83ce759175))
- **core:** Portability migration 000005 (P4.8) ([1179b7c](1179b7cca80e3fef1837dffc0c6251cb879e5690))
- **review:** Валидация C-04 смотрит на драйвер store, а не на его имя ([cb8c819](cb8c819d38199ab0056954e29dcae520e387b6e4))
- **review:** HasRole() принимает BackedEnum — симметрия с мутаторами (хвост B-04) ([07ccbfe](07ccbfe5d6a9392241eb16f177a15c52861f68e4))
- **review:** Не сбрасывать currentPanel на sync-джобах (регрессия C-14) ([c681ee9](c681ee9f90376ac234f58c5fab2e55adc3cc65b2))
- **review:** NULL-safe unique + дедуп существующих строк в миграции C-16 ([4bd209e](4bd209e00970840f05efce7257f431813d340ecc))
- **review:** Атомарная запись scope_class в assignScopedRole (хвост C-11) ([69d9e1a](69d9e1a98074aed0a8c1d32745b2b444bcc66c59))
- **review:** Резолвить morph-алиас сущности в read-path query-scope (хвост C-10) ([5c555f1](5c555f1d832f84add17b53974d39668617e22716))
- **core:** Populate AccessDecision::winningSource in Authorizer::explain() (C-15) ([f9c744b](f9c744b68c448cae223a35ef6608740c2c8b1bb6))
- **core:** Reset currentPanel before each queue job (C-14) ([b3948cd](b3948cdc78fb1607b0e853f4d84852c6560daf2e))
- **filament:** Escape LIKE metacharacters in DirectGrantResource user search (C-12) ([a7e713e](a7e713e73fe078e69332beb09610df1bae35db6e))
- **core:** Flush DirectGrant's original panel cache on panel change (C-09) ([8cdc33e](8cdc33ebb745d4828b7b68c6d56a49ab2a51458a))
- **scope:** Close two gaps left by C-11/C-10, caught by the item's own grep-gates ([7a8a10c](7a8a10c0bf10785cb44720736d0317af61206021))
- **build:** Give composer test a 1G memory limit (D-06) ([7570017](7570017f2b8a0461c142a8ecb0cb732ee3860353))
- **core:** Widen panelId/role to string|BackedEnum across entrypoints (B-04) ([ed64c93](ed64c931f4b36756da231ac6b55ce41fe9c71897))
- **core:** Warn when the epoch bump runs without a lock (C-05) ([d514a86](d514a86f36c26bdebcdd04d1a410c9d6c744e309))
- **core:** Fail fast on infinite TTL + persistent cache store (C-04) ([53fa4c4](53fa4c4e3ec1f42d53eec0d40da6bc22d37256aa))
- **core:** Warn loudly on stale scope_class (C-03) ([fef343a](fef343a5a66370ed70ee0cf0fea9bb1ce6a271ad))
- **scope:** Fail-closed on_missing_panel config (C-02) ([4426206](4426206f4b9c2b380972e4f7e7cd8d4754791c3c))
- **filament:** ResourceGate defers instead of denying (C-08) ([0950f0e](0950f0e977a1de3a5a49a2761777b9d7ef2a7eec))
- **core:** Use getMorphClass() in grant read/write paths (C-10) ([a53f45c](a53f45cf8f0b192317ee63030254956b6a3b3a42))
- **core:** Remove class_name/scope_class from mass-assignment (C-11) ([c44fb98](c44fb98e91de8ee37458af66d5806d287ec21c5c))
- **context:** Reject wildcard in context grants (C-13) ([81c3a5d](81c3a5d48fecaf17a4935ed04bd5caeed5402ce1))
- **scope:** Apply query-scope in console/queue contexts (C-01) ([eed9099](eed90994f44a602a1e2ad0b8109967753e102437))
- **core:** P2.2 — исключить литеральный '*' из dynamic-сопоставления при wildcard OFF ([6bead71](6bead7110625be7ac6e9968797873e075515bdf9))
- **core:** P2.1 — Log::warning-паритет в EnumPermissionCatalogBuilder ([b1de1ac](b1de1ac5d478aef8e5f5a7ac52d5654b32b51421))
- **core:** Symmetric removeScopedRole panel semantics (T2, P1.3) [**BREAKING**] ([b972162](b9721626f1349d912bb65d8ace57a24357338ead))
- **core:** Atomic epoch bump in PermissionCache (T6, P1.2) ([58ed1c4](58ed1c4bdb79c5315a20ed122c098034b6b9f286))
- **core:** Panel-aware query-scope + eager-load recursion (T1, P1.1) ([c166538](c166538123de78f8fa196b37d3f47c1a0887846a))
- **filament:** F12 — wire user_label_column config through 4 read sites ([5d215cb](5d215cb1b98f2f8e346771e9e9610375eecc2aa8))
- **core:** Robust guard:doctor + catalog for Filament/enum panels ([bfc6813](bfc6813bf5a47d38ff7ed4da0358ce80175b4d0f))
- **core:** Refresh permission-cache epoch key TTL on every forget ([e3e33c3](e3e33c3c2d41b9e0ea5388e04c88bb148a173a85))
- **context:** Bust cross-request permission cache on context grant revoke (PR #91 H1) ([dd7b35f](dd7b35f66b26d2d00bbd145c892f92d6d31e2959))
- **core:** Remove dead code — PanelManager, PendingGrant, DiscoveryService (F31) ([4f9a835](4f9a835196852c9334e7af262fc594c91dcac968))
- **context:** Read context_roles table name from own config, not core ([f87a0d5](f87a0d517b804d9da47eaf079e97cb8df2c6bf67))
- **filament:** PermissionEnumGenerator respects schema case/key config (F11) ([f365b8d](f365b8d2f7e237c2909a194bf6f8d26b1743fe81))
- **filament:** F39 — default panelId from config in AzGuardPlugin ([a3c6b4e](a3c6b4e3552c805963c77ba333275f991a1cd55b))
- **core:** Route CLI commands through Config::*Model(), validate keys on role-permissions add/sync (F32) ([99ba112](99ba112cddc4db04a996f6782dca8a7b44197e36))
- **core:** Unify CLI prefix to guard:, drop dead self-referential $aliases (F51) ([b4a0e00](b4a0e006520fb859a1505d720fb8a8cd22d1352d))
- **core:** Scoped-role panel isolation, catalog & cache correctness ([0c3a225](0c3a225ee73443a321cc4c8052b5cfe82225e8e1))
- **core:** Close Phase 1 — remove dead guard:revoke, first-class-callable, retarget test ([53db430](53db4303c6dd6ddba2e087659ae39b849ea30b97))
- **core:** AbilitiesDto::toArray() больше не утекает не-bool свойства ([677adaf](677adaf2f1907900db27b8f9845f0b22d7604006))
- **abilities:** Добавить AbilitiesDto::make() — единственный способ собрать DTO ([6098a45](6098a451dad7cbf4a06ea9c80784dec2fa2149ca))
- **core:** Guard:grants/guard:revoke используют grantable_type/grantable_id ([f34585c](f34585cb076e65cfde3b91515a3c2c5339f44e25))
- **docs:** Make the VitePress base track the repo name (fixes Pages asset 404) ([8f632d1](8f632d110c7ecd29260ee6c437837f61d04db19d))
- **filament:** Correct DoctorPage view namespace to az-guard:: ([16ae70b](16ae70bfd68010ba96445f839938a57f53225eef))
- **core:** Remove orphaned policy stubs that generated hasAzPermission() ([3f94701](3f947015885a035e950bf6a4147fb0fc2165dfca))
- **core:** Scope AuthorizesPermission checks to the policy's panel ([f8a0d17](f8a0d174e7ddfa370a1810a11a49eb1819ed5ae5))
- **filament:** Resolve the user model from config, not a hardcoded import ([f95beee](f95beeee7f7fd47b84e8acc11634420598fb6c81))
- **filament:** Migrate management UI to Filament 5 namespaces ([f59d10e](f59d10e5f7724597d637a51f5f2b6c0890ab8c75))
- **provider:** Move PermissionCatalog singleton registration to boot() ([599c526](599c5260d708464ffd397b8882f4c150c8418f98))
- **cache:** Recompute PermissionSet when cache returns non-array data ([a8b2c64](a8b2c64d38a24a6fffb8bfe4e0c9cc0d72f04143))
- **S1:** Add missing return types to HasAzGuard::scopes() and HasDirectGrants ([47526a2](47526a2ab84d4878f88894bfd7f646e64e784a5d))
- **EX1:** Translate all Russian diagnostic strings in GuardDoctor to English ([436c519](436c519040a99554d528109a3d1cc27458ba4573))
- **EX1+DX1:** Translate Russian strings to English in AzGuardManager ([5325bed](5325bed11137c7c039ebefddf573810987fd09f2))
- Replace /guide/getting-started with /guide/quick-start in docs/README.md ([2d5510c](2d5510ca3d0f31ed7f7818edc8a19b6ddd077c96))
- Add recipes/index.md, fix getting-started link in README ([48ec687](48ec68793733a3686e7a4c90b39bfdec5612eac4))
- Fix dead links — getting-started → quick-start, recipes dir → recipes/index ([fc8cacb](fc8cacb763b1c5f0fd8edf5686895f83a098495b))
- Resolve conflicts by adopting main versions of basic-usage, blade-directives, prerequisites ([2ebe2df](2ebe2dfb0703279789d6ef29b8b7c156b346bf49))
- **sprint-9:** Pint + UserWithDirectGrants factory ([5ab6887](5ab68873e432e12d5bc5d0925ed417c9a028d8f6))
- **filament:** Compatibility audit — Filament 4/5 fixes ([7c99be2](7c99be2964617faa7a2f2dff2a7a7d70b16548de))
- **filament:** Support Filament 4 and 5 (^4.0 || ^5.0) ([dcefb5b](dcefb5b9f19aa4975b359a1aa9badb15733fad94))
- **docs:** Set base: '/azguard-private/' for GitHub Pages subpath ([c5b3919](c5b391923a2b50da0e91c70f4f6cd27ae4696853))
- **docs:** Add "type":"module" to package.json (VitePress ESM-only) ([2d7e6e5](2d7e6e58d9c5838bb642cf5d4ad8b067b56d90d3))
- **docs:** Use npm install instead of npm ci (stub lock-file) ([bd164e9](bd164e9c6ad4f82f4fdfa0190f57d2e10fcc863a))
- **ci:** Fix release-drafter trigger (pull_request_target), scope pint to changed files only ([a1a1f0b](a1a1f0ba796e60d087c38bce251f71c897bdbc03))
- **deps:** Rector ^2.0 (phpstan ^2.0 compat), min PHP 8.3, pest ^3.0, testbench ^9.0|^10.0 ([a473c77](a473c77568493e08478a81ce6b1be1c5a4cacd74))

### CI/CD

- **mutation:** Закрепили native Pest ratchet ([3c0db5e](3c0db5e3c83c4bacd6f7dc33becf9e9e98dcbfee))
- **mutation:** Переключили gate на native Pest ([f17541f](f17541f2c0cdfae43ea4503e7f9a337b79548ca2))
- **mutation:** Публикуем JUnit для Infection ([a1fac5e](a1fac5e4bb5fb930b35554c2763ac9e205e1a0d8))
- **test:** Добавили DB matrix и PG test mapping ([425c93b](425c93bf5173831ff3246609a0d198ddc7d3de88))
- Realistic coverage gate — exclude declarative Filament UI, min 50% ([5766b01](5766b0171bc54b0ecf804f77e9c109b55b3431ee))
- Fix invalid tests.yml — drop secrets from the if condition ([deda34a](deda34ab4ca4d3b19bfe328a24508fc8384f1365))
- Fix failing checks — CodeQL php (unsupported), release-drafter perms, Rector ([d1cb38a](d1cb38a4ab8fd8e598997c8bf31796a44855cfe7))
- Add type-coverage gate (Pest, min 98%) ([3ac2f7a](3ac2f7a06cd9c9f1b16a30f60e6194ce19a7d894))
- Expand test matrix + add CodeQL and zizmor (supply-chain) ([fbd7769](fbd77695ae5a26fe6863d543b8a61e6b186d3588))
- **sprint-6:** Release workflow, dependabot, CHANGELOG, commitlint, contributing guide ([e0b6eca](e0b6ecab6a2cb99198651bcae2c49a555eff1429))
- Upgrade Node.js to 24 in docs workflow ([214ae78](214ae78b13a4fb5eb862e16ee582c334fd88051f))
- **sprint-5.1:** Harden workflows — mutation main-only, fix xdebug coverage, safe codecov, pint workflow_dispatch ([3376360](337636047f71f05620e6abb9dcc50d928805abc1))
- **sprint-5:** Add GitHub Actions — tests matrix, static analysis, code style, mutation, PR checks ([96154d3](96154d3a27fc8afaf5cbfa9ef5cb0ff55e206cf0))
- Add GitHub Actions workflows (tests matrix, phpstan, pint, docs, mutation, release-drafter) ([0a45d40](0a45d400ec386e81a7a71385b86cdc7750b05d54))
- Add release-drafter config to default branch (required by release-drafter action) ([b10d4be](b10d4be746a57350ea10291bfcff91bef162ad13))

### Chores

- **plan:** Закрыть канон дорожки (P5.1) ([19e8484](19e84849810c5e84560710d42f75dcc951849ac1))
- **mutation:** Удалили legacy Infection runner ([b265061](b265061d235422dc64c5ac8dce0e2044f28052fd))
- **plan:** Escalate P4.14 MySQL rollback contract ([93b01a0](93b01a08ca2375702a5815679f7f1f46e2eac48d))
- **codex:** Add project-native tooling ([ed13827](ed13827125d5184bf03888eb0dd03bdf60e09438))
- **review:** Тест-гигиена — мёртвый console-форс и no-effect use ([49238d9](49238d9506c6a8be639eb352cbc91674892f6b1b))
- **tests:** Remove dead Feature/DiscoveryTest.php reference (D-01) ([1f6e458](1f6e458d8d7c301b196624dc715c1326333e76dd))
- **plan:** Archive 2026.07.17-AZGUARD-TAILS ([14613f6](14613f664c0d31ad6cebd9be8a899bf5f04bbdd3))
- **toolkit:** Fix rector BaseRole skip path, refresh Boost skill for 0.2 API (F54) ([8e08fea](8e08fea1bc4a7d1b6eb48cbf63d2d6b21ce37ce3))
- Перестать игнорировать AI-tooling (.claude/, .serena/, .swissknifeman/) — видимость правок агентов ([6cd9cc1](6cd9cc10f0e37359e22155393ddde8315c9051b4))
- **core:** Remove dead code, close CLI/abilities test gaps, tighten arch & mutation gates ([1ea789e](1ea789eff2082d1a883c30008b41ff911adfb4e8))
- **context:** Delete dead MissingAuthorizationContextException ([6ca0627](6ca062795a5d6071635a7a9803e32a456d4d2626))
- **i18n:** Translate source comments and UI strings to English ([16f4e4d](16f4e4d1a10188d0cd35e78567f293034e18a51c))
- Reframe as the initial 0.1 release ([c08257b](c08257b0465e55c585de6024ca5edee706b5558a))
- Strip private dev tooling and harden dist hygiene for public release ([141d639](141d639396bfd1d405d4d88a63495981428ee807))
- Remove superseded local azguard skills; ignore Claude local overrides ([9b8b29e](9b8b29ebf48b0b4958f70cb80200f835dc3f41eb))
- Green toolchain and open-source package metadata ([c7aa261](c7aa2615f5d9ea099b88cff5a3e6f4b6fbbb4a09))
- **Q1:** Add phpstan.neon (level 6) and rector.php (PHP 8.3 + Laravel sets) ([f7a7e72](f7a7e72d1fc679d8e509a7a52068c105c64fd1bb))
- Add refactoring plan and tracking doc for issue #38 ([f9edb9e](f9edb9e75274a962d9b5bf671253f414789fea23))
- Remove ideas/tmp.md ([d4adfd3](d4adfd31e5682cdcdb95682950afbf3a7f804d5e))
- Remove implemented ideas/entity-scoped-roles-and-context.md ([ce542e7](ce542e78c93bf545a0e9d5daa5da90901b309819))
- Remove implemented ideas/hybrid-permission-registry.md ([82102f8](82102f8d378bb6ded55d5f9adf5dc21aa02995be))

### Documentation

- **plan:** Закрепить per-finding коммиты (P5.1 review) ([272acef](272acef81e6bcd04e30d7655c8d4aee14da3b934))
- **plan:** Формализовать чек-листы аудита (P5.1 review) ([9362a7c](9362a7c5a0c07de262c2c52e32e1d02a2f3e137e))
- **plan:** Добавить дорожку усиления пакетов (P5.1) ([73837ad](73837ad63e175e8519a3e3bea78eb4df24c859f4))
- **plan:** Провели GREEN аудит P4 ([e90b021](e90b02114543d2fc0ff2d3a7ebd748d08608c76c))
- **plan:** Закрыли фазу P4 ([e6985df](e6985dff41ac0369b422be22b01b2ea83ef5b3c4))
- **plan:** Закрыли P4.5 mutation ratchet ([2601554](26015545082909d5bc83a3d82232c4191979fe33))
- **plan:** Возобновили P4.5 native Pest runner ([12d9a97](12d9a9751aeaf6c0894213dc760f1b790128b54e))
- **plan:** Закрыли P4.6 test gaps cleanup ([483129a](483129ac2a3c0bb0f035d95db186a1fd3110aeda))
- **plan:** Начали P4.6 test gaps cleanup ([4fc46bf](4fc46bfabaa6b8544c026d1689ac7b2079d58a46))
- **plan:** Зафиксировали P4.5 mutation blocker ([57b19fd](57b19fdc3874acac86c2c5d1b20007902546c28d))
- **plan:** Начали P4.5 mutation ratchet ([ce10c35](ce10c35e1e3b2a849746cd35c03d4c820e1eb8bc))
- **plan:** Убрали устранённый риск P4.10 ([b8258c5](b8258c57e108f80a67ca4974621d8c97e04a5b56))
- **plan:** Синхронизировали статус P4.10 ([a1a7efe](a1a7efe0fd986135840641528451afdd69d364af))
- **plan:** Закрыли P4.4 Redis race ([0ac9596](0ac9596e0c97c409056bab9da2b0a6e0617d92e3))
- **plan:** Начали P4.4 cross-process race tests ([a577e84](a577e84714fa075f5c5f76cfc77acf9603d26583))
- **plan:** Закрыли P4.3 parallel hardening ([42c63ce](42c63ce5b428029f61eb2e9aa8293ed0ada9aac6))
- **plan:** Начали P4.3 parallel hardening ([9b3615f](9b3615fff2bff4a98223af89d05b0ea42ff64642))
- **plan:** Закрыли P4.10 с green DB matrix ([84e28a1](84e28a16f5e5b8634391cd63c6ce36303c6f00bd))
- **upgrading:** Описали case-sensitive RBAC keys (P4.10) ([3a85dc8](3a85dc864c50ef6ffcb686a0efd0f993b2480b49))
- **plan:** Убрали дублированный scope P4.10 ([a8c34a5](a8c34a55b21a4db9140964f7f3c9c36305408434))
- **plan:** Переоткрыли P4.10 для clean vendor proof ([7d5f35b](7d5f35ba830a28c3fb4d2984d05b8743031169d1))
- **plan:** Закрыли P4.15 с validation blocker ([80b0a47](80b0a47342bf30518a21135f44a711e21a13c0a7))
- **plan:** Design P4.15 ULID refresh isolation ([f80aa3e](f80aa3e76788471e7e855089864bb5dca61eda65))
- **plan:** Эскалировать P4.10 ULID blocker ([ad66979](ad669792c67db96577628d3992777310a3d29bdf))
- **plan:** Закрыть P4.14 savepoint recovery ([d164d94](d164d94aa1f97e60fe0c75ffa18eb61a8a085d89))
- **plan:** Redesign P4.14 driver-aware recovery ([ea7b7a8](ea7b7a87df26247d637a9c8cb1b1512f595748cc))
- **plan:** Закрыть P4.13 SHA-256 repair ([1a9853f](1a9853fe6600e2eedb2571c6657b01ce712f9590))
- **plan:** Спроектировать recovery P4.10 ([950e6b7](950e6b786aeae61f1d61b77c9d8c159882a82849))
- **plan:** Эскалировать P4.10 DB green-proof ([7f4541f](7f4541feb35bbf539043e2b381ebd0328e3963c6))
- **plan:** Close P4.12 morph index repair ([e5407a0](e5407a041512c982c1cdee3863dc8def6ba4462f))
- **plan:** Design P4.12 morph index repair ([c03a040](c03a040c8e634f78985c29ad11b97d1d4219f60d))
- **plan:** Escalate P4.10 UUID index regression ([3411f75](3411f75ecfd9e377952be628d05f475cd46af599))
- **plan:** Закрыть portability LIKE-escape (P4.9) ([e48b3dd](e48b3dd3a1cec8237571a55b5f5d550e59c8dc2c))
- **plan:** Close P4.7 collation hardening ([c2847c7](c2847c7ed74cd3f8fab3dbd59e933822878de0bc))
- **plan:** Закрыть P4.8 portability remediation ([35c319f](35c319f5dd7249684b6b5020ee00a8f133aef7d8))
- **plan:** Закрыть P4.11 wildcard fixtures ([bc22c6c](bc22c6c82f09f0c66719b2375d2389eefc21adf6))
- **plan:** Спроектировать P4.11 wildcard fixture follow-up ([257b3cb](257b3cb7e81b35268fdbd7aeb1af4af39298ee9a))
- **plan:** Escalate P4.8 wildcard defect ([4aff067](4aff0676b41bf1447682b6ab5583364f5985dac1))
- **plan:** Завершить Codex-only подготовку P4 и P5 ([000164e](000164eead09a038489b50677ff576450f7c8d08))
- **plan:** Адаптировать остаток P4/P5 под Codex ([9c687f4](9c687f415b6cdef7871114668500575dd3e740f4))
- **plan:** P4.2 закрыт — БД-лейн-харнесс + фикс фикстуры expires_at (2026.07.18-AZGUARD-STABLE) ([ff73814](ff73814d7d3c61d19abd2bd895c2fd9469ca5115))
- **plan:** P4.1 закрыт — docker-стенд PG16/MySQL8/Redis7 (2026.07.18-AZGUARD-STABLE) ([75253ed](75253edcad76f6d783965860777e1270fd36d564))
- **plan:** Фаза P3 закрыта (2026.07.18-AZGUARD-STABLE) ([9a7fed4](9a7fed42f2ff9356c6dfc05c47b37af270b68b5b))
- **plan:** P3.3 закрыт 🟢 — bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([2c7df4c](2c7df4ca1a0a335359d94a17dd9dd2d37e40972d))
- **plan:** SemVer-политика 0.x + каталог ограничений + UPGRADING 0.2→0.3 (P3.3) ([b7c39a5](b7c39a5e850976f3674fc30413bf3b31b7d6d5ff))
- **plan:** P3.2 закрыт 🟢 — snapshot-гейт заморозки, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([0a8bf4c](0a8bf4c0ff6f8d2dabb29a04162a13a24c4b803d))
- **plan:** P3.1 закрыт 🟠 — cut-line фасада, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([4186070](4186070447b92bf914c7574f5b9599d1f21bebb0))
- **plan:** Фаза P2 закрыта (2026.07.18-AZGUARD-STABLE) ([46e057c](46e057cab55905a5d64b483d83fe0db46059cb99))
- **plan:** P2.10 закрыт 🟢 — фаза P2 терминальна, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([d04f808](d04f80844f0d4fd72de63e5c5e1f1b016a1bf302))
- **P2.10:** Сквозной EN/RU docs-свип под новый API канон ([0de35b0](0de35b0f0f67cb8729a323d6f9a6424fe3d154fd))
- **plan:** P2.8 закрыт 🟢 — headless quick-start + doctor-hint, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([60f18ee](60f18ee3f2863b7740500110ccb612d96bd53557))
- **core:** Headless quick-start + guard:doctor 0-панелей hint (P2.8) ([c9edfc2](c9edfc2165f1090b660871064c9b38d731e26c17))
- **plan:** P2.7 закрыт 🟢 — глоссарий + docs-маршрутизация context↔scope, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([7b6f3dd](7b6f3ddec0464ed6f02a13169c0d11a70c940d0a))
- Словарь терминов guard/panel/context/scope + маршрутизация context↔scope (P2.7) ([f589663](f5896634398e2cf6665c5dcd62d64068abb735cf))
- **plan:** P2.9 закрыт 🟠 — wildcard-флип Hierarchical + R7, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([f15a3ed](f15a3ed77198b85ad54e7515a54a568b8465027c))
- **plan:** P2.5 закрыт 🟢 — cut-line target-спека фасада + D29, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([88be7f8](88be7f8d0d405f711d4a488ef26cbe6b94dc2bd5))
- **plan:** Cut-line target-спека фасада AzGuard — вход заморозки P3 (P2.5) ([2a395ef](2a395efeb25127cf114419dd7da456bdac79dd29))
- **plan:** P2.6 закрыт 🟢 — AzGuard::fake() Testing DX, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([9190e8d](9190e8d6359213609992fd16ce6bd0cfdfe756df))
- **plan:** P2.4 закрыт 🟠 — config→fluent Filament-плагин + middleware ::using(), bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([bc38b45](bc38b4559c2b3c4ee344ddc86ef0b3cc797e94fa))
- **plan:** D28 — Routing актуализирован построчно, fable только где оправдан (2026.07.18-AZGUARD-STABLE) ([578f87c](578f87cd17284afbdf8bc8c3c487d9d69656cd56))
- **plan:** P2.3 закрыт 🟠 — единый immutable grant-корень + TTL-парность, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([bb761c2](bb761c27096e694bfe95ed85689e8ae49dae41e1))
- **plan:** P2.2 закрыт 🟠 — 6 контрактных швов разрешены, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([4965bdc](4965bdc88fa1d64578151d2411edf67c4790c438))
- **plan:** P2.1 закрыт 🟠 — Support/ распущен, bookkeeping+handoff (2026.07.18-AZGUARD-STABLE) ([e2e76d1](e2e76d15ca53ce84f2c0bbaccff8d69580578725))
- **plan:** Фаза P1 закрыта (2026.07.18-AZGUARD-STABLE) ([44ed50a](44ed50aec7970f3815a7c59e5508ccd3a11f570f))
- **plan:** P1.4 закрыт — bookkeeping, фаза P1 терминальна (2026.07.18-AZGUARD-STABLE) ([d18553a](d18553a838a39b4557ee670e307a747b4380225e))
- **review:** Отчёт adversarial review диффа P1 — 16 находок, вердикты (P1.4) ([398985f](398985fc6818ac349d0fadcea1e26dfdae18c649))
- **review:** Задокументировать гэп model-event флаша при mass-update (C-09) ([0701d03](0701d036420c8832e205b2e36c42adcd6e8c0153))
- **review:** Задокументировать best-effort семантику winningSource (C-15) ([8d91611](8d916119ff1734d58b4acd53e90e738058b9edad))
- **plan:** P1.3 закрыт — bookkeeping (2026.07.18-AZGUARD-STABLE) ([11ac0ae](11ac0ae61d95eb76209e039e00b8cd8eb333194f))
- **core:** Sync AzGuard facade @method types with AzGuardManagerInterface (B-11) ([01bd42f](01bd42fe214a63b6b837a51bad01011b750de504))
- **core:** Narrow PermissionCatalog::flush() docblock (B-07) ([ee8f15f](ee8f15f187a991857766775a06391964973aef7b))
- **introduction:** Implement AzGuardUser in golden-path User snippets (A-02) ([501c8ef](501c8efc8137e2d718410134835bf7bdb649ecb1))
- **introduction:** Sync RU Laravel version claim (A-01) ([3d49729](3d497295d566bffdda0fcd90a4bc23892bf5f72d))
- **introduction:** Sync doctor output and Laravel version claims (A-01) ([42e6dce](42e6dcec7de6d9d9ce09633e79ad80a906fc8c4e))
- **plan:** P1.2 закрыт — bookkeeping (2026.07.18-AZGUARD-STABLE) ([54088bb](54088bb6ead9e295f4a8c67167a9d35efe74baf8))
- **advanced:** Document FakeAzGuardUser/FakeGrantSource, drop false claim (A-05) ([cd4311b](cd4311bdc9677da714f60cbe1d2cdc6b8cc6a852))
- **advanced:** Document the 5 swappable seams + sync configuration.md (B-01) ([d000e56](d000e561af88099cc6bea9363c031fe0a00ced6a))
- **plan:** P1.1 закрыт — bookkeeping (2026.07.18-AZGUARD-STABLE) ([9f8fe3e](9f8fe3eee1c475b6fbb29af7ae6d4c8fb4d4f3da))
- **plan:** Фаза P0 закрыта (2026.07.18-AZGUARD-STABLE) ([bdf9416](bdf941695792baec02f834267b1cf83badd746c7))
- **plan:** Bookkeeping P0.6 — статус 🟢, D9 (гейт утверждён), борд P0 6/6 Done, Phase Handoff, финальный handoff под plan-close P0 (2026.07.18-AZGUARD-STABLE) ([8e96e8e](8e96e8ebbb6d6df94a2039d417794cba888e3d17))
- **plan:** P0.6 — синтез аудита: REGISTER 44 находки (дедуп 0, re-rated 0), бэклог W0/W1/W2=1/12/14 + 9 кластеров P2 + 3 отклонения, гейт владельца утверждён (2026.07.18-AZGUARD-STABLE) ([eabd431](eabd4316b9a0a2675fca06d73b336dd260973ca1))
- **plan:** Bookkeeping P0.5 — статус 🟢, Update Log, финальный handoff под P0.6 (2026.07.18-AZGUARD-STABLE) ([fac809a](fac809af4f2864a2c8b139daf9b12838d317be38))
- **plan:** P0.5 — ось D (структура/тесты): чеклист C-D1..C-D12, классификация Support/, корзины baseline 17+6+12, 9 находок (2026.07.18-AZGUARD-STABLE) ([c5441b0](c5441b0d53be108f668672ad75f73b16a80ca44f))
- **plan:** Bookkeeping P0.4 — статус 🟢, Update Log, handoff (2026.07.18-AZGUARD-STABLE) ([cb1ff81](cb1ff812e85f3c622c05197606bca42cafef5ae6))
- **plan:** P0.4 — ось C (корректность/безопасность): чеклист C-C1..C-C10, 16 находок (1 Blocker), вердикты 4 отложенных breaking (2026.07.18-AZGUARD-STABLE) ([6f13af9](6f13af90cca2883c91598215bb0d987bae9fb00f))
- **plan:** Bookkeeping P0.3 — статус 🟢, Known Deviations (17 @method), Update Log, handoff (2026.07.18-AZGUARD-STABLE) ([7e91060](7e9106065d0a63f103c9e85ec024b16f37b4f3cd))
- **plan:** P0.3 — ось B (fluent/расширяемость): чеклист C-B1..C-B10, классификация 17 @method фасада, 11 находок (2026.07.18-AZGUARD-STABLE) ([46e85ef](46e85efa3af2c702d10ba8cf3ed9556d9d7010f1))
- **plan:** Bookkeeping P0.2 — статус 🟢, Update Log, handoff (2026.07.18-AZGUARD-STABLE) ([4ecd31c](4ecd31c6dcba16f9e65977b64489d6fbc3061c32))
- **plan:** P0.2 — ось A (интеграция/DX): чеклист C-A1..C-A11, 8 находок, словарь поверхности (2026.07.18-AZGUARD-STABLE) ([374134b](374134b8b267268818e6885edda273a653a9299d))
- **plan:** Bookkeeping P0.1 — статус 🟢, Update Log, handoff (2026.07.18-AZGUARD-STABLE) ([11c9fe0](11c9fe0fda529fca895c086e5cfc357493f35b56))
- **plan:** P0.1 — RAG-добор fluent/DX: 5/5 вердиктов preseed подтверждены первоисточниками (2026.07.18-AZGUARD-STABLE) ([c6b254b](c6b254b2b8094d37e112838cbcdf877537e2098a))
- **plan:** Design pass 2/3 — детализация P0 до DoR, workflow D8, finding-template (2026.07.18-AZGUARD-STABLE) ([084d8fc](084d8fcfcefa5952565f85b0da9aad47392dcbc6))
- **plan:** Приоры fluent/DX + RAG-preseed (2026.07.18-AZGUARD-STABLE, pass 1) ([8d1f3a8](8d1f3a804d72b530d3de44c1fa983dc0d4f3582b))
- **plan:** Создан план 2026.07.18-AZGUARD-STABLE (design pass 1/3 — new) ([cd643f2](cd643f2a7b4e357ad8b58d9cedcfc1cbd55763e7))
- **plan:** Миграция root/ 2026.07.17-AZGUARD-TAILS → docs ([91194d1](91194d1afa84770e925eff3e2f152ca7c81764f1))
- **plan:** Фаза P1 закрыта, план закрыт целиком (2026.07.17-AZGUARD-TAILS) ([406d211](406d211de1e9fd5b0515481e726af780439d8bcd))
- **plan:** Фаза P2 закрыта (2026.07.17-AZGUARD-TAILS) ([2775b6f](2775b6fe99b927a9f52188926a8b4c7ba0d2909c))
- **plan:** P2.3 закрыт — bookkeeping (2026.07.17-AZGUARD-TAILS) ([f9750fd](f9750fddece41716756ed53bc03bca16ac16bc29))
- **plan:** P2.2 закрыт — bookkeeping ([b0a2697](b0a2697334885827b62d8285939aaf80072c22d1))
- **plan:** P2.1 закрыт — bookkeeping (2026.07.17-AZGUARD-TAILS) ([7e326a7](7e326a7a07fdde453eb03a444d6c2a483af54682))
- **plan:** P1.3 закрыт — bookkeeping (2026.07.17-AZGUARD-TAILS) ([8c86254](8c8625487feb55b366c3941a7bdd24c86a5dfa76))
- **plan:** Handoff.md — Q1 разрешена (D10), Next → /task:plan-design P1.3 ([7ee6b16](7ee6b160800d69c57cb395df49288175d616ae3e))
- **plan:** Q1 разрешена владельцем — Вариант B (D10) ([4d08839](4d088395bed81b7d61a9fd6c2eca4eb389e64576))
- **plan:** P1.2 закрыт — bookkeeping (2026.07.17-AZGUARD-TAILS) ([4d74fb4](4d74fb42cdafb22435d256fe2d91365dbb0832a7))
- **plan:** P1.1 закрыт — bookkeeping (2026.07.17-AZGUARD-TAILS) ([f2ff441](f2ff44129c4be2a9df4b7ff8ed9df4cf91fbc673))
- **plan:** Honest Status column + REMAINDER_REPORT for tails T1-T7 ([1451c6f](1451c6fe8a226eeb1ad1245b0b5b43ec392fb828))
- **filament:** Fix F39 config key and F41 mislabel in CHANGELOG ([a29b816](a29b8168ad7b907e35fdef402ec00091a1093647))
- **core:** Document bfc6813 doctor/catalog fix and InvalidCatalogException removal ([48d45a8](48d45a8f8cfa557f908e5722ab9ad38956d7054d))
- Bump PHP floor to 8.3+ and add composer-driven doc-lint gate (F43) ([d5d4819](d5d481990b8405428d53a9a50b6b9318ace7f78c))
- Standardize examples on App\Guards\ generator namespace (F45) ([8c98364](8c983640db93b29c45493b80d8c24a1c512d85c7))
- Clear RU leaks in EN pages and bring RU translations to parity ([ad2f6dc](ad2f6dcf7e441f4f2c3795beb909b061a84ee60e))
- **core:** F44 — generate full CLI reference from registered commands + drift test ([759201c](759201c579d37a1cad574c0cc848274806ea2558))
- **core:** Compilable custom-catalog-builder example using SimplePermissionDefinition & registerCatalogBuilder (F24) ([bd26931](bd26931d0da6776e41fa814c591347bbae2e15b3))
- Rebuild frontend abilities guide on real AbilitiesDto + abilitiesFor API (F23) ([20571e8](20571e861bcf99b15f10216f8bce875d51bfba37))
- Track Phase 2 residuals (T1–T7) for end-of-plan cleanup ([3000c7f](3000c7fe4a95f36515a80c9ca059af746d4b641f))
- Architect review report and phased execution plan ([e153e3a](e153e3af14e593ccdbac36edffc49e6a008a2f31))
- Document the 0.2.0 API — contracts, super-admin, integration recipes ([9cdc986](9cdc986c7f7e51530e4d9d515346ff96dcea6255))
- Drop the redundant guide/ wrapper — sections are top-level roots ([0902087](090208778c6016daef9b9a0e2827be2916010a22))
- Group guide pages into section folders matching the sidebar ([eb1f0da](eb1f0da58cd3bb2b4066cb8eb6105678040b6c6d))
- **ru:** Enum/class-first sweep of core Russian guide pages ([806e84a](806e84a46fb837a792383c24e09aca0169dec836))
- **guide:** Enum/class-first sweep of core concept pages ([0a090ab](0a090ab621a4bd5be37916c1ae404969de89ad27))
- **guide:** Enum-first role examples in quick-start and roles ([872ca40](872ca40bb250b1ce85c077ed75f374df631e1633))
- Rewrite README English-first (enum/class-first, panel-first) + UPGRADING ([10f706a](10f706a637087f2b4466933cba2be678012d2079))
- Rewrite guides, READMEs and CHANGELOGs against the 1.0 API ([f87c02e](f87c02e6d9ff79abed8d6cd3dd690b034b6a26ec))
- Refresh guides for the new API; add core README; fix README badges ([0097a0c](0097a0c16870c52a38ca90dafdcc1ad69c3f6e72))
- Open-source hygiene files and Filament README ([4df140a](4df140a317f951a94f718fc408aade6ae3b81711))
- Replace string permission literals with enum constants across all docs ([f410514](f41051477987929b014a9f99ae114cc251ad904c))
- **ru:** Translate Basic Usage group (11 pages) ([5433dc7](5433dc71228fb3ba11582037c5987e6a2256a1f1))
- **ru:** Translate Introduction group (prerequisites, upgrading, questions-issues, changelog) ([ff91aa2](ff91aa2cf9154274ed08e5be9839bd6a04cd8db8))
- **ru:** Translate upgrading, questions-issues, changelog ([82d04c2](82d04c2e92a559eafc734624fb83eb21cab312f5))
- **ru:** Translate guide/prerequisites ([f4c0a68](f4c0a6850669462fdef4787379269a1a7292cea1))
- Sync conflict-free basic-usage, blade-directives, prerequisites from restructure-nav ([89e2d2a](89e2d2a988e319f586219034e00864ee66be09e7))
- Final overhaul — hero landing page, expanded content, full nav ([e1ed633](e1ed633d6255fa86527d538e753db9dc7c006e04))
- Resolve conflicts — merge best of restructure-nav + final-overhaul ([cb41cdf](cb41cdfb7a76068cd1177f9de4e28d0c12a58bbe))
- Expand basic-usage with full lifecycle — scopes, queries, counting, relationships ([f31333b](f31333b2e3c11ce3d51f03a815de0eb02a1e3fa9))
- **phase-1:** Expand blade-directives with @role/@hasrole/@hasanyrole/@hasallroles/@unlessrole/@hasexactroles/@haspermission/@canany and guard support ([e1975f9](e1975f9ac6103c5a764bc551b2ed08e537058127))
- **phase-1:** Expand prerequisites with contract, naming conflicts, DB limits, UUID note, FK support ([162453b](162453bd4c5d531cfd05d42e27b5a9f0ec68fe32))
- Expand roles.md with inspect API, query scopes, gotchas, sync examples ([1de76f1](1de76f169c2b5f705ad33444ee68cf2221ee3bdd))
- Expand permissions.md with inspect methods, sync patterns, gotchas ([20d5514](20d551484f8d393a75da2de2639a4ae23e5ea477))
- Add 'use roles first' warning and when-to-use guide to direct-grants ([8ae30ee](8ae30eec677dbf4e112c011511849de2f424d095))
- Expand blade-directives with full directive list, canany, best practices ([176ef3a](176ef3a8cf2e0054f798b21bc3cdf03aba8e54a8))
- Expand basic-usage with full lifecycle, scopes, and query patterns ([f8b651a](f8b651aa223aa20d1d348b5724e5aa3d2b0806a2))
- Expand prerequisites to match Spatie-level detail ([5001cf5](5001cf5d65d8431071736ab8ea704158070779d5))
- Expand permissions.md with sync/revoke/inspect API, query scopes, naming gotchas ([ea7aacd](ea7aacda9d58cf97bda19c6fb9fecd9748464dc5))
- Add permission-first warning to direct-grants, restructure into clear sections ([9cef75c](9cef75c871ff35c67a05929c91ce91338ab7d0d1))
- Expand blade-directives with @canany, @role helpers, best practices and permission-first guidance ([6816cb3](6816cb38fe89675542f2b0f3276ef917b58a092e))
- Expand basic-usage with full lifecycle — create, assign, sync, revoke, inspect, scopes, query patterns ([5930175](59301759949d337f36447085a7da0941fba6d329))
- Expand prerequisites to match Spatie-level detail ([9ba597a](9ba597a6bbed4f73334866e6f0c177e762f463c0))
- Restructure nav into Basic Usage / Best Practices / Advanced sections (Spatie-style) ([9669b8a](9669b8aaf7d7a46998a017ac922c9f4c7c167739))
- **full-revamp:** Second pass — expand thin pages, add Events/BestPractices/Exceptions, new recipes, Filament Doctor, logo confirmed ([fb02aa0](fb02aa00a4e7201a577ca3b92bc056891487c4ef))
- Logo on homepage, fix old method names, expand all existing pages with real examples ([73cbeb2](73cbeb2c964e1eb2a665fbe9fabb129e8189b2c9))
- **sprint-9-cleanup:** Remove obsolete pages, rewrite why-azguard & entity-scopes, add changelog ([df5b82d](df5b82d8bf7decbfedad554ad681d00d1835e983))
- **sprint-9:** Restructure navigation, rewrite pages, new reference files ([45ab482](45ab4824d4490fb874a7ee6a19753434052df984))
- **context:** Guide/context.md + CHANGELOG Sprint 8 ([62f361b](62f361bb6c3b5795cc48e884e734f52e714f8da3))
- **sprint-8.3:** Install matrix, expanded getting-started, full filament guide ([61f4e69](61f4e69e63413b407d84821fbe83636f64b22c80))
- Remove VuePress config (replaced by VitePress) ([2b889e3](2b889e36429c836c487bea7c10c1543ad0a16d7a))
- Migrate VuePress → VitePress + GitHub Pages integration ([a09e105](a09e1059cd26fdb46d0a6025ecaf7b5ea1bf1faa))
- **sprint-3:** Update AI-CONTEXT with sprint status, role contract, entity-scopes ref; expand comparison.md ([0dbbdfd](0dbbdfdf6d8516cdaab40f19a3d42fbeb475b2f0))
- Add Why AzGuard, competitor comparison, updated sidebar and homepage ([6e0dbe0](6e0dbe0d22543f3d2ef441bde36dbae4c145ad18))

### Features

- **dev:** Docker-стенд с реальными БД для тест-углубления (P4.1) ([1a9e46b](1a9e46bde6f062d87f4a86ded7e307579a4471a7))
- **core:** Cut-line фасада AzGuard по facade-cutline.md/D29 (P3.1) ([448028c](448028c53bae81db57411b51242877f909e209c0))
- **core:** Дефолт wildcard-грамматики — Hierarchical, legacy за deprecated-флагом (P2.9) [**BREAKING**] ([73072fd](73072fd563054c1feda365d99d8ac1b90bbd806a))
- **core:** AzGuard::fake() Testing DX — Recorder + assert* (P2.6) ([c07f157](c07f1578db240735df70834ca958c8d37a9c958b))
- **filament,core:** Config→fluent Filament-плагин + middleware ::using() + единый порядок аргументов (P2.4) [**BREAKING**] ([250e53a](250e53a0bb5c6145eaaf1684f2895c6029df14b0))
- **core:** Unique constraints on model_has_roles/model_has_scopes (C-16) ([f6643f3](f6643f3b9fb14ad617b1e5962541b149e6b70b66))
- **filament,context:** Enforce page/widget perms, sync codegen with runtime, context write-API ([2c274fc](2c274fc393f844e12a8fbc957412dc573f793f61))
- **filament:** Enforce custom page/widget access (F13, adoption-blocker) ([fd470c7](fd470c7d2068cb70106c9d7e7a8eb2832be83ab6))
- **context:** Auto-alias middleware + write-API for context grants (F14) ([9f98c48](9f98c4811e6810bbc03b1bd24bfcd669ecf4a169))
- **filament:** Add user_label_column config key ([f2cdbab](f2cdbab6f536a15577de2aa121b254614837dbe3))
- **core:** Complete CLI surface (role lifecycle, explain/abilities, structured output), unify command prefix ([352f894](352f8941ffd9c52799822adbe0a9309b33b26222))
- **core:** Guard:explain / guard:abilities inspection commands (F53) ([af30bcc](af30bcc83c32b9c8857d54f534c07f50d7dda68e))
- **core:** --force + argument-driven make:guard-* commands ([84962f7](84962f7e682ba70c582020141e7b3095d9b64630))
- **core:** F52 --json + non-zero exit code for guard:doctor и guard:catalog:validate ([4892088](4892088e5b02a5242fc6b1e7ec7febf4e1157d9b))
- **core:** Guard:role assign/detach console command (F15) ([341d51b](341d51b3a6a29a663053567eec53e605d0e33b1f))
- **core:** First-class extension surface — swappable resolver/manager/matcher, catalog-builder API, decision event, abilities projection ([a0d40d2](a0d40d2176a55e25d4c26f6d97458eb4e5e09b58))
- **core:** Public actor contracts, isSuperAdmin, correctness fixes, testing kit [**BREAKING**] ([b81f7f2](b81f7f274aaa1340408ffade2504337d427d4dbc))
- **core:** Ship FakeGrantSource test helper for consumers ([bc056a0](bc056a008faa55afa96bf752f7aeebb93b4d44a3))
- **core:** Ship a Laravel Boost consumer skill (azguard-development) ([e962201](e9622014e08e2776c55eff0de0fe0e415e3e110a))
- **core:** Enum-first role & panel scaffolds wire the permission enum ([e707fea](e707fea4778c27978f7e460d0f2de331232e7112))
- **core:** Make:guard-panel auto-registers the panel in config ([c8f4988](c8f4988ff947750407cd504f8553d7297c044adf))
- **core:** Azguard:super-admin command to promote a user ([f6cba6c](f6cba6c9f097f3f245854daf4c1ef2ffaca54e05))
- **core:** Azguard:install command + php artisan about section ([cf9caf1](cf9caf1e218065ba0fd746b5a0d9f780ac0e4192))
- **core:** Class-based permissions via the Permission contract (class-first) ([5bca415](5bca415f0369e8de580b68abadc41ba851a1a51d))
- **core:** Accept a backed enum as the panel identifier (typed panels) ([9c2a09b](9c2a09baf1875aab9e005d75c61738fd218b1f61))
- **core:** Resolve and check roles by class-string (class-first) ([09c9d32](09c9d32ddb6942e571e4d97a8c89fb17ee47d786))
- **core:** Let roles declare permissions as enum cases (enum-first) ([3fb6810](3fb6810c26c8beb8192a045653a51ecf69ea2b4a))
- Harden core, filament and context for a stable 1.0 [**BREAKING**] ([3e9adb1](3e9adb1edf4093e183557e5c96e6a0e2af2790e9))
- **core:** Тип morph-ключей через config (int/ulid/uuid) ([1f32196](1f321966eede6e911669aa1da1e335da7c439361))
- **filament:** Add the policy source — generate Laravel policies ([c027b06](c027b06e85932a05755913317947be4e3c3f7cac))
- **filament:** Config-driven, zero-boilerplate resource permissions ([5739a11](5739a1126490732e9f82ae0aa7de9f59aa0f029f))
- **middleware:** Add azguard.panel_check — combined SetCurrentPanel + permission check ([126571a](126571a7a2449179a93c7aea14e7e466cc07d4c3))
- **resolver:** Add fail_on_source_exception config — skip or rethrow GrantSource errors ([33e5d3e](33e5d3e2a3faea825ec07b726df5832161cf9617))
- **config:** Add grant_sources allowlist to enable/disable GrantSources via config ([0fb6072](0fb60728ddef632e3be5664246849c999e0e5d48))
- **manager:** Add tryPermission() — soft-resolve without RuntimeException ([903acd1](903acd1470103345ecf7916ca24c5379e4d8cf14))
- **DX2:** Add @elseazcan and @unlessazcan Blade directives ([714613c](714613ce571fc5159eca2b0256c8c9e29646d350))
- **i18n:** Add complete Russian translation for all docs pages ([ca7894e](ca7894e10d23b34361cc07659d589ec0ae929e45))
- **ru:** Translate all documentation pages to Russian ([e8c0eb6](e8c0eb610b0be8dbc19b491bf0bebcd5d6eeb6ab))
- Add logo SVG + full EN/RU i18n structure ([a6422cf](a6422cfe2d70c71000bad03a4caeb1156eb45b77))
- Add Support\Config helper — centralize all config() calls ([b7fbcca](b7fbccaa483c735c1e6ed71ac8e238690784a216))
- **filament:** Add DoctorPage — диагностика AzGuard в Filament UI ([d6c52ca](d6c52ca69346faaa98333c87a67eae7ad68586b9))
- **filament:** Add Create Grant UI to DirectGrantResource ([37bc9db](37bc9dbf5ac3d633354570a17cbabcb3b66579ed))
- **core:** Context-aware hasAzPermission + hasAzPermissionIn ([9ce1f6d](9ce1f6d98344b37a816cf1db7801a3b14990796b))
- **context:** Sprint 8 scaffold — packages/context ([8a64d42](8a64d42a95319f990c0369e17191f6745cd9aade))
- **context:** Scaffold packages/context — Sprint 8 ([6ccc5a4](6ccc5a416e4104ff20199e3b1460711ec6376084))
- **filament:** Code/custom role toggle in RoleResource ([c83ae30](c83ae30f3d80359d491cd29ae6f0cdc06cd7b0b7))
- **filament:** Sprint 7 — Roles UI, Role Permissions UI, Direct Grants UI ([d40e492](d40e492c5be0642c53a7b6e3f63cade0167ece1c))
- **core:** Sprint 6 — wire Registry into container, Authorizer → EffectivePermissionResolver, register catalog+missing commands ([a4bfaa1](a4bfaa1f4f32cbbed3e594e481928117777b8665))
- **grants:** Phase 10 — config, interface, CHANGELOG, AI-CONTEXT ([65812b5](65812b5f1046fa7974bec7caf554970323b01806))
- **grants:** Phase 9 — DirectGrant model, migration, HasDirectGrants, GrantBuilder, AzGuardManager helpers, tests ([d168fa0](d168fa0be0862e746aaacc9904e3685fcdc6022b))
- **grants:** Phase 8 — @azdirect Blade directive, DirectGrantPolicy, Facade @methods, docs/guide/direct-grants.md, sidebar ([26fabfc](26fabfc855baedc687f21fc711229371f7a959de))
- **grants:** Phase 7 — GrantCommand, RevokeGrantCommand, PruneGrantsCommand + ServiceProvider wiring ([02fcb6f](02fcb6f301d317f2581c427399ed595e71f80576))
- **grants:** Phase 6 — HasDirectGrants trait, CheckDirectGrant middleware, end-to-end test ([8a73c9d](8a73c9dbe8799daa2187a76ad876342c6bb6cdfc))
- **api:** Phase 5 — Fluent GrantManager, events, AzGuardManager grants API ([198c7eb](198c7ebc7413a4fcb63765047a7ce8da886566a6))
- **cli:** Phase 4 — guard:grant, guard:revoke, guard:grants, guard:role-permissions commands ([23a5857](23a5857c1f85da3f67f8f59b8e726e0942c0e627))
- **registry:** Phase 3 — DatabaseRoleGrantSource, DirectGrantSource, migration, models update ([5d2a528](5d2a52823179c20cb9af4e02a850e0459821707c))
- **registry:** Phase 2 — CatalogListCommand, CatalogValidateCommand ([2d20ab2](2d20ab2673613206afd9294403683fa617783895))
- **registry:** Phase 1 — bind catalog+resolver in ServiceProvider, add getPanel() to Manager, add tests ([118a20c](118a20c764a2042321afb32fb34ed9f703ec95a9))
- **registry:** Phase 1 — PermissionCatalog contracts, builders, PermissionSet, GrantSource, EffectivePermissionResolver ([571fabf](571fabf43b86e39df6a1df1e9419b5f7b1300fb3))
- **sprint-4.1:** Register ListScopedRolesCommand in ServiceProvider ([618a390](618a390ee2a5ebc24894d6b6a908a5edecabd38b))
- **sprint-4.1:** Gate integration tests + azguard:list-scoped-roles command ([984a724](984a724671c74e347426532a58e9cddc43350ed1))
- **sprint-4:** Entity-scoped roles — migration, ModelHasScope, InteractsWithAzScopes API, docs ([fb30c3d](fb30c3d88fd4b964c7014626c87d43623b844e49))
- **provider:** Register SyncRolesCommand ([16ba373](16ba3735106c1ee2a47fcd3c200dc7bf51a73cd9))
- **commands:** Add SyncRolesCommand (azguard:sync-roles --dry-run --panel) ([d48cbd9](d48cbd9dff080ab86a1b93417ec65785d9364870))
- **trait:** Add assignRole, removeRole, syncRoles, getRoleNames with events and cache invalidation ([6085623](608562351e7b2f96e356729709ee356de312a05a))
- **roles:** Add SuperAdminRole, deprecate Support\BaseRole alias ([0cc1e99](0cc1e99d395fefd6153669f5a27e5c755a99cb57))
- **events:** Add RoleAttached and RoleDetached events ([4b0560e](4b0560e69c4cae9b941b06872b19329dd6d554ad))

### Performance

- **filament:** Memoize DoctorPage diagnose, batch-resolve grant labels (F29) ([e1d007d](e1d007de17fa126602f1485375fe0a46421d0692))
- **scoped-roles:** Cache scoped role DB query per user+entity type per request ([6dff5c6](6dff5c60eba5fe8aa9db87239bd55ff519197df3))
- Deduplicate discoverPermissionEnums in diagnose() — single fs pass per panel (closes #50, #58) ([9f76d63](9f76d637915de0b59ff57411a9f17726fbcd70c7))
- PermissionSet — O(1) lookup via hashmap index, fix isWildcard O(n), remove toArray() alias (closes #49, #57) ([85df0ee](85df0eea355d33f4540a2073cc16cdab9eff684c))
- Fix N+1 in HasRoles::syncRoles — batch load via whereIn (closes #48, #55) ([461dda4](461dda415871c4387777bca36f346dbbfdd298e6))

### Refactoring

- **static-analysis:** Сократили P4.6 baseline ([4f78d13](4f78d139140bd859d7fcb07e599f8ada12f9fffd))
- **core,context:** Единая immutable grant-грамматика — fluent-корень forUser()→inContext() + TTL-парность context (P2.3) [**BREAKING**] ([278cdfc](278cdfcbf07848a4a35d55038efbac0f6fe3fd2a))
- **core,filament:** Контрактные швы — 6 структурных phpstan-baseline разрешены уточнением контрактов (P2.2) [**BREAKING**] ([0724a24](0724a24e6de57e0bcf499c0566272cc22962f73e))
- **core:** Роспуск Support/ — доменные неймспейсы Panels/Permissions/Configuration/Runtime/Database, корневые PanelProvider/PermissionKey к доменам (P2.1) ([9cc1897](9cc1897ba537265f078ad1a29432e7f16fb3a1a8))
- **core:** Declared @api boundary, unified key normalization, honest static-analysis baseline ([5539ba3](5539ba36927673e41e8100370eabd1baa9eeba96))
- **core:** Annotate Role model attributes with @property ([6718403](67184035d0ffb2e6f1990abe143f0fa1ce9966ea))
- **core:** Type model relations and the role-model resolver ([4331032](4331032e9f20745a8802a1642decfa558a88ceaf))
- **core:** Type the role-permission contracts as list<string> ([2147d50](2147d504a1ba9f61bf8183748495b074bca995f5))
- **core:** Octane-safe scoped-role cache; centralize resolver access ([42058c1](42058c162d646d979cc499d9a0549889524c7f02))
- **core,filament,context:** Unify public API naming [**BREAKING**] ([62ecdc4](62ecdc4b7fc7b5bf508f5cbae981a19f46938f9c))
- **core,context:** Remove deprecated/dead code, unify context bridge ([f353ff5](f353ff5e0051fb9e9b489be6fa7486b0cce22d6e))
- **manager:** Allow registerPanel() to accept Panel directly or callable(): Panel ([cce3289](cce328989bcde442cf4714bf64466b914e40bd63))
- **grant-source:** Replace magic int priority() with GrantPriority enum ([9caa275](9caa275db4777e3c2ed5a352c22bf2052388bf7e))
- **cache:** Use 2D array [userId][panelId] in PermissionCache, drop str_starts_with iteration ([648e3b5](648e3b54babd70ff918b3bbd6a36600b13445a3a))
- **blade:** Extract auth()->check() into BladeHelper::authed() ([9761e7a](9761e7acf73ee8402f0b65934cdfa9ae40e7cda0))
- **typing:** Introduce PermissionContext interface, replace ?object $context ([f5d4bff](f5d4bff2d4aa133e57b947965e776e46dfcf8385))
- Replace DiagnosticsService with @deprecated thin-wrapper (closes #47, #54) ([ed338f9](ed338f9b909c9493c887b969ae3191541596f388))
- Inject AzGuardManagerInterface into Authorizer, mark RevokeCommand final ([e77bdb3](e77bdb32a02345437021b952d355a08cffc39cf5))
- Replace 'az_guard_filter' with constant SCOPE_KEY in HasScopedRoles & HasScopes ([a652a5f](a652a5f7e9bf5c465bbca5fb54d9d00aa42ea5fa))
- **D5:** Replace raw config() in Models with Config:: typed methods ([cb2c78b](cb2c78b9b45f022e3fd12e2d8939c6474f7e94cb))
- **D4:** Replace raw config() calls in Sources with Config:: facade methods ([5478b4b](5478b4b0e93abf7361a815e95c290cb57631f408))
- **N1+N2:** Rename GuardDoctor→AzGuardDiagnostics, AzGuardContextBridge→AzGuardContextProxy ([9794cb2](9794cb2dd2fe363b990ef3602bbf5959b8297343))
- **P2:** Extract resolveRole() into shared ResolvesRole trait ([88ae441](88ae4417c0bd2db899a8d863f00cbfb3fc3ef3a9))
- **N3:** Rename PermissionResolverCache -> PermissionCache, keep alias for BC ([a03a0dc](a03a0dc6b95e74995ffdd1d72efbc774d9aef959))
- **D5:** Replace raw config() calls in Models with Config:: methods ([271ee4c](271ee4c323eb5d621186316dfd85632536d0de1b))
- **DX4:** Add PanelNotFoundException, remove Russian text from AzGuardManager ([3af3261](3af3261f0aef8fb4f51a55e3c527f7a01060a761))
- **DX3:** Narrow catch(\Throwable) to catch(\Exception) in checkPermission() ([46d5ea7](46d5ea7a551038b97703033216790115ecfe1408))
- **D2:** Remove resolveScopeRole() from HasScopedRoles, delegate to resolveRole() ([8065fb7](8065fb715fe6c69c8d40dd38c608d5d8918850a5))
- **N2:** Rename HasScopes -> HasScopedRoles, keep HasScopes as deprecated BC alias ([13d2497](13d2497506eafb5beea83e829478f7198ea4fb53))
- **N1:** Rename GuardDoctor -> DiagnosticsService, keep GuardDoctor alias ([bc56473](bc5647332aee78f71aca03fdb9fde6fb6234b498))
- **DX4:** Add PanelNotFoundException, remove Russian strings from AzGuardManager ([a13475f](a13475fa4b13f2821392f6d3e32a39dbadfcb59b))
- **DX3:** Narrow catch in checkPermission() from Throwable to AzGuardException ([205cd45](205cd456a05d9a5234e441d3a8c58dc3ba343161))
- **D5:** Replace raw config() in DirectGrant model with Config:: ([9ca1851](9ca18515088615b684e9c073d519f441d023ed23))
- **P3+D3:** HasScopedPermission() — 1 JOIN instead of 2 queries ([5428afd](5428afd78f17aa80bc58ece4c897c2635cda494a))
- **D2:** Remove resolveScopeRole() from HasScopes, reuse resolveRole() ([6ad1ceb](6ad1cebeee4259b42a729c74882a00772e4cd58c))
- **D1:** Add PermissionSet::fromRawKeys() and use it in all 3 Sources ([cb0373b](cb0373b3babcec71a443b374d8b7851ecf234011))
- Fix Config::cacheTtl alias, add PanelResolver, Role::findByName, fix usleep, sort sources once ([21e6cc2](21e6cc25d3690a861ab80ea84d0b7631756f7d76))
- PHP modernization ([c05be95](c05be9573e46effe071afeef24ae6c660df9f39e))
- Clean method names — убрать Az-префиксы, HasScopes ([9d22278](9d22278a76a69983c02d477f7c3bca22700844eb))
- Use Support\Config in AzGuardServiceProvider ([ea7f682](ea7f6828e70081b11d61314ae0830ad539dba2c0))
- Use Support\Config in HasAzGuard ([6b4913a](6b4913a493ddd8a47bf079f229c2afe7ae25d556))

### Reverts

- **tooling:** Stop tracking private AI-dev infrastructure ([166a779](166a779890b7262e7fa73de6070782009a477aa6))

### Testing

- **api:** Normalize self types across PHP versions ([0a97ffd](0a97ffd302246d1f6de04d6f8ce1803ab02735a0))
- **parallel:** Изолировали generator fixtures ([3cb2f2d](3cb2f2d8fcfc60db3b942646179fbafccad77435))
- **mutation:** Изолировали generator fixture в coverage workers ([0e0c797](0e0c7979c613bbe462ee5c9f83aaa6000f00ef9f))
- **infection:** Стабилизировали Pest mutation launcher (P4.5) ([d83fb86](d83fb86e187dde27d881e36662b56958f52cd000))
- **infection:** Запускаем mutation через Pest (P4.5) ([7951414](79514141af38476a9b0a3a4ed607317f698279fd))
- **cache:** Добавили кросс-процессный Redis race (P4.4) ([07cac2b](07cac2bc5318b72257545299b7e60989ca6cdd52))
- **infra:** Добавили parallel Pest hardening (P4.3) ([23a2a7a](23a2a7a2b57cacdaf458634d877fdcfe54f80257))
- **database:** Изолировали ULID refresh-state (P4.15) ([704d16b](704d16ba8f1eef0a6e2b34c4dfb629601f62b103))
- **database:** Изолировать PG rollback savepoint (P4.14) ([976909e](976909e2e8ecb0d9641a42ebd05718be95235778))
- **wildcard:** Сделать role fixtures portable (P4.11) ([cda13a8](cda13a86c591e92eb079adc5374fae5a7449737e))
- **core:** Cover uuid portability in migration 000005 (P4.8) ([91a67d7](91a67d74904baee53e86a31e473caeafa81a901b))
- **db:** Commit real-DB lane harness + fix context table fixture (P4.2) ([208943e](208943eab3c7b11a36ea085e0036b91725fe8427))
- **core:** Snapshot-гейт заморозки @api-поверхности — reflection-фикстур + режим регенерации (P3.2) ([27d46b7](27d46b778d3cb31bcf7ed761fc287e948f635042))
- **review:** Доказывающий mass-assign регрессионный тест для C-11 ([f0055ae](f0055ae7a5a843818b2bb18dd45eb41908ffafc4))
- **arch:** Extend "contracts are interfaces" to AzGuard\Registry\Contracts (D-04) ([cc067fc](cc067fca9c23d8214bf5dd5380321b3d53a84d08))
- **core:** Reverse contract/trait parity check — trait ⊆ contract (D-03) ([d93ced5](d93ced56f63ccc263c0cb8f3e2d090d56f2a6d88))
- **core:** Cover grant_sources allowlist + fix misleading reorder claim (B-10) ([8fc1cf6](8fc1cf67a6c871a78128aa3762d3f6ac75f05336))
- **context:** Cover config-overridden MergeStrategy swap (B-09) ([03d2330](03d233022310a72e391c79517232bdc7e7b3f808))
- **core:** P2.3 — rollback-тест миграции 000004 (T5) ([f75e0ef](f75e0ef3c89f2db58e292a71ecd3b41651f68d66))
- **infra:** Per-package Infection + honest coverage/mutation gate in composer check ([9c10245](9c10245dd62ee4f2d5f5deb9bbb8371ebdf276f6))
- **arch:** Add architectural ratchets for immutability (F49) ([cac1066](cac1066fb915375732b2785da90bb6002d008da2))
- **core:** Add contract-parity arch test for FakeAzGuardUser & FakeGrantSource (F20) ([e819a08](e819a088e096501c7dc04c568ba5c702fb642d24))
- **core:** F19 CLI feature matrix + AbilitiesDto unit suite ([bb974bc](bb974bc2bb495964d8e19366373975545802eeae))
- **filament:** Prove F13 page/widget permission enforcement (URL, not nav) ([cda6741](cda6741e4e659331bf032694cc58d88d5a657b04))
- **context:** Prove F14 auto-alias middleware + context write-API CLI ([623601d](623601d0c924fdeab4b3f59bcfdca320349445df))
- **context:** Prove context-roles table name is read from context config (F26) ([60a549f](60a549f0abb25625ab0543128e42bc9297a0be71))
- **filament:** Prove F29 — DoctorPage memoizes diagnose() 1×/request, DirectGrantResource batch-loads grantable (no N+1) ([7d6b94b](7d6b94bfebd4299bc6b868cb6c7f19a17dbd7a1b))
- **filament:** F11 — enum codegen honours schema case, round-trips runtime key ([42bc600](42bc600ae4e742c06700b58b1b501d9bbc87422e))
- **filament:** F39 — canary-prove getPanelId() reads config, not hardcoded fallback ([229d15c](229d15ca2696888f1d00b3ef4c3433ec4965b56d))
- **cli:** Cover guard:explain / guard:abilities verdict inspection (F53) ([0ea50fa](0ea50fab97d21cf6aa48e2b76a6e3c2e3c9d272d))
- **commands:** Prove F33 --force overwrite + argument-driven make:guard-role ([ad9bb0b](ad9bb0b7c956967f15774149ad321c307f4ac232))
- **commands:** Prove guard:role-permissions uses Config::*Model() + catalog key validation (F32) ([955e090](955e09089eab21c5a6d2a74cb8e7ceb7ce9acb02))
- **commands:** Prove F52 OutputsStructured --json payload + non-zero exit codes ([a1eeee1](a1eeee147ad1e00f7d342a7f4ed669259f23a4c0))
- **core:** Cover guard:role assign/detach lifecycle (F15) ([4d01b00](4d01b00f34449231670360b401576e23d4ef3fb4))
- **abilities:** Prove toArray() omits non-bool subclass properties (F4) ([daf9225](daf9225270dc12c1e63583c72360a8da57c9dd08))
- **abilities:** Cover AbilitiesDto::make() factory and flag resolution ([7d890f8](7d890f8f0e25903d762c4c58df3a439b292e8fee))
- **grants-cli:** Cover grantable_type/grantable_id round-trip for guard:grants and guard:revoke ([c5966db](c5966dbe2a37f04158036fde4c13ced8a646ec5c))
- Cover 1.0 hardening — scoping, panels, context strategies, cache ([d0b69d6](d0b69d62a3594a36734a852bf7bd65c68b3e34c4))
- Consolidate to a single canonical test suite ([30aca72](30aca724250025281a15010d556ffb431ccf6ceb))
- **context:** Run the merge-strategy coverage in the root suite ([4b50bd1](4b50bd13b74529cad6d95fe9eeeeb3769e5a939d))
- **filament:** End-to-end integration test for resource enforcement ([b24c30a](b24c30a93332074a8c78d549402b8af8b40dbd81))
- Add unit tests for HasPermissions, PermissionResolverInterface, ResolvesUserModel ([4c2b522](4c2b5221b72c0aef86f6a0636594bad6356aebc6))
- Fix old method names + add HasAzGuardTest, HasScopesTest, AuthorizerTest ([cce3da5](cce3da5e2d65d9569fb6a70782be252f30abd6b8))
- **sprint-9:** Context, DatabaseRole, DirectGrants + ContextTestCase ([7c04edc](7c04edcf6e49232d9a4793cb1a3595f302aa233e))
- Registry unit tests — PermissionSet, CompositePermissionCatalog, EffectivePermissionResolver, PermissionResolverCache ([fc30bad](fc30badab650c8563ad716f1024e74eb9d483b2f))
- **context:** Sprint 8 #5 — unit tests for packages/context ([cbaa9a3](cbaa9a30a9c071c4daaeb4458cce2681f8431302))
- **filament:** Add Unit tests for Filament plugin package (Sprint 8.2) ([be28ff0](be28ff0e33af703109a94a0d1307bba070e34874))
- **sprint-4:** Unit tests for entity-scoped roles API ([7c76703](7c767032a03ad85befd81cfdbec933641bbfa2ae))
- **sprint-2:** Add unit tests for HasAzGuard role management API and extended Authorizer Gate tests ([6de2182](6de21827fd6880b82e9e11d33b37ba531476176c))
- **sprint-2:** Add feature tests for azguard:sync-roles and azguard:cache-reset commands ([7facf31](7facf31088ba84405eb54a5c91684df52e1099fd))
- **sprint-2:** Add feature tests for SetCurrentPanel and LoadAzGuardRoles middleware ([c5e1ebd](c5e1ebd88332ce93c0d7f9293c14bcaa1aa0b481))
- Add DX helpers to Pest.php for creating users with permissions/roles ([7191c91](7191c9162d188af9bbc904fdc66252f0b4e50f8c))

### Build

- **docs:** Add EN/RU documentation parity gate (F42) ([5314c13](5314c137e2a544161c532b67a36090a6ebe1726f))
- Lockstep 0.2.0 + branch-alias for path-repo consumers [**BREAKING**] ([bb1dffb](bb1dffb92497802376cf0248672635f9e9109eb5))
- **deps-dev:** Update infection/infection requirement (#88) ([667e5bb](667e5bb82678552fb8e7bc7c12e1d279d8c07b10))
- Composer check/fix aggregate scripts + FUNDING; readonly grant source ([8ea15b5](8ea15b5384f29b195ac3ce1715bf6fd489b78342))
- Support Laravel 13 and clean up the root manifest ([7801e04](7801e044f8e6c7d64253c2e4424dbb052fbc3d78))
- **deps:** Bump symplify/monorepo-split-github-action (#80) ([6c4c556](6c4c5560bbf6fcf3e7830e9d9ff6f3f761f4c740))
- Lockstep packaging and subtree-split release pipeline ([f40bfb7](f40bfb72b16da9e7f00b8f821361eecc4d27399d))
- **deps:** Bump release-drafter/release-drafter from 6 to 7 ([968d53b](968d53b43901f37acd936205a60176e0604a3081))
- **deps:** Bump dependabot/fetch-metadata from 2 to 3 ([bfdd681](bfdd681e4c7b88bb8fa87f83566366dc41f96d81))
- **deps-dev:** Bump the dev-tools group with 2 updates ([a79ce46](a79ce4655f6e1e4f54bc9ca813c3c9318b27b949))
- **deps:** Bump actions/checkout from 4 to 6 ([65cd322](65cd322a1074faaa864bad07ec1b665db4993b43))
- **deps:** Bump softprops/action-gh-release from 2 to 3 ([abcc019](abcc01922bfb9a680d92772f61b665ab578aa660))
- **deps:** Bump orhun/git-cliff-action from 3 to 4 ([a971274](a9712745eb0005ed3783f7083f0c1c976aa66e3a))

### Merge

- Bring in origin/refactor/plan-remainder (PR #92 filament/doctor hardening) ([8dd8c44](8dd8c44a0a3de526b71b043eae8a507ed2c49df8))

### Style

- Fix remaining Pint violations in context tests and core rector.php ([eb6f408](eb6f40871415fe4e85dba04e5369d35767f4a96c))
- Apply Pint Laravel preset and Rector PHP 8.3 rules across all packages ([a159af7](a159af72c74feaac2e6712bb1e1ab9fd3b555afb))
