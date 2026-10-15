# Filament и публичная Laravel-интеграция: аудит перед freeze

Дата: 2026-10-15. Исследован текущий checkout `/home/vostrikov/projects/packages/azguard`; production-код, основная тестовая база и план не изменялись. Локальная проверка: PHP 8.4.1, Laravel 13.33, Filament 5.8.4. Все ссылки `packages/...`, `tests/...`, `docs/...` ниже относительно корня репозитория.

**Вывод:** отделение Filament от внутренних механизмов ядра в целом выполнено последовательно; редакторы пользуются публичными менеджерами и повторно проверяют недоверенные значения. До фиксации поведения стоит исправить две существенные границы: мутации связей сейчас авторизуются чтением, а стандартные виджеты ресурсов выпадают из enforcement и каталога. Дополнительно воспроизведены три ограниченных дефекта: lifecycle страницы роли, конфликт импортов в генераторе и повторные обращения к справочнику при выводе выдач.

## Основание и границы проверки

Прочитаны корневые инструкции, README обоих пакетов, `docs/guides/filament.md`, Laravel entry points и весь `packages/filament/src`, шаблоны страниц и конфигурация Filament. Просмотрены соответствующие fixtures и feature-тесты: plugin/keys/definitions/tenants, resources/pages/visibility/forged calls/surfaces, role/permission/grant editors, custom fields и generator. По Laravel проверены service bindings, Gate bridge, panel/user middleware, route attributes/strict checks, queue panel hint, HTTP responder и существующие lifecycle-тесты. DevTools просмотрен в части регистрации и вызовов генератора из Filament; полный аудит внутренних генераторов в этот срез не входит.

Завершённый план `2026.10.10-№1-AZGUARD-CORE-STABILIZATION` использован как контракт, а не как доказательство отсутствия дефектов:

- `knowledge/architecture.json#operation-chains`: Filament делегирует проверки общей цепочке scalar/visibility; отсутствующий точный query adapter не должен расширять список.
- `knowledge/architecture.json#ownership` и `knowledge/target-structure.json#filament`: отдельный пакет работает через поддерживаемые API/SPI ядра и нейтральные описания схемы.
- `phases/P3/P3.10.json:6` и `:15`: задача переносила 14 внутренних классов; изменение поведения Filament было явно вне её scope. Поэтому сохранённый старым тестом небезопасный default ниже не является регрессией relocation, но остаётся обоснованным предметом рефакторинга до freeze.
- `knowledge/p4-execution.json#gate`: qualification сохраняет поведение и публикационный контракт; она не покрывает отсутствующие сценарии resource widgets/hydration.

Применены навыки `laravel-filament:filament`, `quality:code-review` и перед запуском probes — `laravel-testing:test-isolation-guard`/`laravel-testing:laravel-testing`. Нормы навыков не использованы как самостоятельное основание severity.

Root агент обнаружил устаревшую PSR-4 metadata в `vendor` и обновил path packages. До подтверждения исправления собственные проверки не запускались. Затем выполнены только изолированные SQLite `:memory:` probes. Полную qualification запускает root; этот отчёт не утверждает, что отдельно проверены MySQL/PostgreSQL/Octane или все версии Laravel.

## Подтверждённые находки

| ID | Severity / уверенность | Проблема | Изменение контракта до freeze |
|---|---|---|---|
| FIL-01 | Major / высокая | Attach и associate используют read permission для изменения связи | Да: определить write abilities для связей и их default mapping |
| FIL-02 | Major / высокая | `Resource::getWidgets()` не входит в enforcement/каталог/генерацию | Обычно достаточно внутреннего исправления обнаружения; определить границу page-local widgets |
| FIL-03 | Minor / высокая | `ViewRole` проверяет `view` только при mount | Внутреннее исправление lifecycle |
| FIL-04 | Minor / высокая | Генератор создаёт невалидные импорты для одинаковых basename | Внутреннее исправление генерации |
| FIL-05 | Minor / высокая | 25 строк выдач вызывают 40 одинаковых subject SELECT | Внутренняя оптимизация render; новый SPI пока не требуется |

### FIL-01. Пользователь с read grants меняет связи через штатный AttachAction

**Место:** `packages/filament/src/Internal/Authorization/AuthorizesActions.php:98`; применение mapping в `:35–39`. Список предлагаемых стандартных abilities — `packages/filament/config/azguard-filament.php:35`.

**Триггер:** ресурс использует `AuthorizesResource`, relation manager — `AuthorizesRelationManager`, в нём добавлен обычный `AttachAction`. Пользователю выданы только `orders.view_any`, `orders.view`, `products.view_any`, `products.view`. Права `orders.update`, `products.update` или отдельное право изменения связи не выданы.

**Результат:** пользователь прикрепляет product 4 к order 1; строка pivot сохраняется. `OrderResource::can('update', $owner)` одновременно возвращает false. Это не требует вредоносного PHP-хука или явного `authorize(true)` со стороны приложения.

Причина: `ability()` объединяет `export`, `attach`, `associate` в `view_any`. Filament 5.8.4 в `vendor/filament/filament/src/Resources/RelationManagers/RelationManager.php:346` для Attach/Associate проверяет read-only mode и возвращает `null`, когда редактирование manager разрешено. Затем именно AzGuard предоставляет разрешение по `view_any`. Фильтр предлагаемых записей в `AzGuardPlugin.php:549` проверяет их видимость; это не проверка права изменить связь.

**Доказательства:** `evidence/filament/FilamentProbeTest.php::testReadOnlyGrantsAuthorizeAttach`; actual DB assertion после вызова Livewire action. Основной `tests/Feature/Filament/Gate/SurfacesTest.php:80` также намеренно закрепляет успешное прикрепление с одними `products.view_any/view`. Для Associate отдельный DB probe не запускался; тот же mapping и та же ветка vendor показывают аналогичный путь статически.

**Характер дефекта:** это недостаток принятой семантики, а не ошибка переноса последнего плана. Публичный guide описывает `{slug}.{ability}` и делегирование relation manager ресурсу, но не сообщает, что право чтения даёт право attach/associate. Существующий тест доказывает поведение, но не делает смешение чтения и записи безопасным default. При использовании связи как назначения команды, доступа или владельца последствия могут затрагивать полномочия; в репозитории доказана обычная мутация pivot, не конкретная эскалация в чужом приложении.

**Рекомендуемый ремонт:** определить отдельные `attach`/`associate` и согласованные `detach`/`dissociate` abilities либо явно документированный write mapping; читать `view` для selectable rows как дополнительное условие. Учесть owner record и selected related record, чтобы политика могла различать, к чему прикрепляют и что прикрепляют. Существующие явные `authorize(...)` приложения должны сохранять возможность ужесточения. Согласовать генератор/default abilities и описание имен до freeze. Не строить отдельный authorization engine для relations.

**Приёмка:** read-only набор из probe не изменяет pivot/FK; разрешённое отдельное write ability допускает мутацию; отказ политики owner или related record запрещает её; неподходящий tenant/scope и скрытая related row остаются запрещены. Проверить attach и associate через реальные Livewire action, отдельно multiple selection. Обновить прежний положительный тест с read grants.

### FIL-02. Виджеты ресурса выпадают из `enforce`, `FilamentSource` и генератора

**Место:** `packages/filament/src/Internal/Authorization/FilamentKeys.php:210`, особенно `:214`; enforcement перебирает только полученные keys в `packages/filament/src/AzGuardPlugin.php:462`.

**Триггер:** штатная конфигурация Filament: `OrderResource::getWidgets()` возвращает widget, страница списка подключает его через `getHeaderWidgets()`. Сам widget не добавлен в `Panel::widgets()`.

Filament специально регистрирует resource widgets отдельно от panel widgets: `vendor/filament/filament/src/Panel/Concerns/HasComponents.php:649`. Однако `FilamentKeys::widgetTargets()` читает только `$panel->getWidgets()` и никогда не проходит `Resource::getWidgets()`.

**Результат, вариант A:** обычный widget без `AuthorizesWidget` успешно отображается в header страницы enforced resource. Plugin boot не выдаёт ошибку, permission отсутствует, `Widget::canView()` остаётся true. Это противоречит `docs/guides/filament.md:43`: каждый widget должен быть авторизован AzGuard или явно исключён. Не доказана конкретная утечка production-данных: probe рендерит строку `widget`; доказана пропущенная проверка для компонента, который в приложении может отображать данные и агрегаты.

**Результат, вариант B:** widget использует `AuthorizesWidget`, но definitions берутся из `FilamentSource`. Его permission не возникает в каталоге, поэтому такой widget нельзя нормально открыть через ожидаемый автоматически построенный набор разрешений. Enum generator и doctor используют те же keys: они тоже пропускают этот компонент. Наличие trait вручную устраняет не весь сценарий.

**Доказательства:** `testResourceWidgetEscapesEnforcement` реально boot-ит enforced panel и проверяет `assertSeeLivewire(AuditUnprotectedWidget::class)`; `testResourceWidgetMissingFromCatalogue` устанавливает `FilamentDefinitions::Resources` и подтверждает отсутствие `widgets.audit-protected-widget`. В `AuditWidgetResource` оба widgets объявлены стандартным `getWidgets()`. Это обычная конфигурация framework, без override защищаемых методов `getAuthorizationResponse/getEloquentQuery`.

**Рекомендуемый ремонт:** собрать единый полный inventory поддерживаемых компонентов, включив widgets всех resources и resource configurations. Дедуплицировать class, применить существующий exclude, использовать один inventory для enforcement, `FilamentSource`, генератора и doctor. Для динамически возвращаемых header/footer widgets страницы явно определить гарантию: известный declaration либо runtime fail-closed check; одно обещание «каждый widget» без проверки этой границы оставлять нельзя. Не инстанцировать произвольные страницы с I/O при компиляции ради тотального отражения PHP.

**Приёмка:** обычный resource widget без trait/exclude приводит к configuration error; тот же widget с trait появляется в enums и Resources definitions, скрыт без grant и виден с grant; configuration-only resource и `WidgetConfiguration` ведут себя так же; совместное объявление widget на панели и ресурсе не создаёт duplicate key; явно исключённый widget сохраняет native Filament semantics. Проверять не только `canView()`, но и рендер resource page.

### FIL-03. Открытая страница роли читает детали после отзыва `azguard-roles.view`

**Место:** `packages/filament/src/Resources/RoleResource/Pages/ViewRole.php:35` и `:81`.

**Триггер:** пользователь открыл `ViewRole` с `azguard-roles.view_any` и `azguard-roles.view`; затем отозвано только `view`, а право входа и `view_any` остаются. Пользователь отправляет следующий Livewire `$refresh` существующего компонента.

**Результат:** refresh успешно формирует подробности роли. Проверка `RoleResource::can('view')` находится только в `mount`; при hydration родительский Filament resource lifecycle проверяет общий `canAccess()`/`view_any`. `row()` снова получает роль и schema без `view` check. Свежий mount той же страницы после отзыва возвращает 403.

**Доказательство:** `testRoleViewNotRecheckedOnHydration` после отзыва подтверждает наличие `entry.enter`, `pages.*` и FQCN `GrantedMemberRole` в новом ответе; свежий компонент получает `assertForbidden`. Основной `tests/Feature/Filament/Roles/RoleResourceTest.php:86` проверяет только исходный запрет страницы. Гарантия повторных проверок Livewire заявлена в `docs/guides/filament.md:39`.

**Ограничение влияния:** страница read-only; permission нельзя изменить из этого компонента. Пользователь уже видел прежние данные до отзыва, поэтому это не доказательство раскрытия изначально неизвестных данных в данном probe. Дефект — продолжение серверных reads и возможность получить обновлённое описание роли после отзыва. Severity ограничена Minor.

**Рекомендуемый ремонт:** перенести проверку role-detail access в lifecycle, выполняющийся при mount и hydrate, или централизовать её перед `row()`/content. Сохранить locked target fields и повторную проверку managed panel.

**Приёмка:** mount с grants → revoke `view` → следующий refresh даёт 403 и не формирует подробности; восстановленный grant снова разрешает страницу; отзыв `view_any` тоже закрывает компонент; сохранены 404 для отсутствующей роли и запреты подмены panel/role.

### FIL-04. Генератор enum ломается на одноимённых классах из разных namespace

**Место:** `packages/filament/src/Internal/Console/EnumMarks.php:21–24` и `:32`; группировка всех widgets в один enum — `packages/filament/src/Internal/Console/FilamentGenerateCommand.php:119`; запись результата — `:201`.

**Триггер:** два widgets, например `Stats\\SalesWidget` и `Other\\SalesWidget`, имеют разные permission keys (допустимый `$azguardKey` решает коллизию keys, как рекомендует сам `FilamentKeys`). Генератор собирает их в один `WidgetPermission`.

**Результат:** `EnumMarks` вставляет оба `use …\\SalesWidget;` без alias и оба `#[ForFilament(SalesWidget::class)]`. Полученный PHP-файл не парсится. После обычной генерации дефект проявляется при следующем autoload/discovery; с режимом, загружающим enum для policy, он может проявиться сразу.

**Доказательство:** `evidence/filament/enum-marks-probe.php` вызывает непосредственно production `EnumMarks::add()` и сохраняет `generated-widget-permission.php`. `php -l` этого результата завершился кодом 255: `Cannot use …Stats\\SalesWidget as SalesWidget because the name is already in use`, строка 10. Это probe генерации маркеров, а не полный CLI запуск для двух widgets; причинная цепочка CLI→`groups()`→`EnumMarks::add()` прочитана в исходнике.

**Рекомендуемый ремонт:** использовать fully qualified names в атрибутах либо детерминированные уникальные aliases с учётом существующих imports. Не выводить имя символа только через `class_basename()`. Проверять итоговую синтаксическую корректность до замены файла; сохранить точную class identity каждого маркера.

**Приёмка:** полноценный `azguard:filament:generate` для двух одноимённых widgets с разными keys возвращает 0, `php -l` проходит, `ReflectionEnum` видит два правильных `ForFilament` FQCN. Добавить конфликт с уже импортированным enum/model/attribute basename; повторный запуск/`--force` сохраняет существующие правила.

### FIL-05. Вывод выдач повторяет одинаковые обращения к subject directory

**Место:** `packages/filament/src/Internal/Editors/GrantEditor.php:354`, subject label в `:363`, actor label в `actorLabel()`; вызов для каждой строки — `packages/filament/src/Internal/Editors/ListGrants.php:252`. Default directory выполняет новый lookup на каждый `describe`: `packages/core/src/Directories/Internal/ModelSubjectDirectory.php:54`.

**Триггер:** одна страница 25 grants одного пользователя, часть actor также указывает на этого пользователя. Размер страницы соответствует default UI, произвольный масштаб не нужен.

**Измерение:** `testRepeatedSubjectDirectoryQueries` создаёт 25 реальных permission grants через public writer, получает page(limit:25), затем прогоняет ровно production `GrantEditor::row()` по результату. Зафиксировано **40 SELECT к users, все 40 имеют одинаковый SQL**: по одному для каждой subject label и ещё для model actors. Ранее короткий probe 25 вызовов одной строки давал 25 SELECT; финальное evidence использует 25 разных grants реальной страницы.

**Влияние:** локальная SQLite проверка не доказывает неприемлемую latency, поэтому это Minor. При удалённом directory либо сетевой БД повторные обращения будут дороже; утверждение об их конкретном времени не делается. Query budget поиска subjects (существующий тест на 10 000 users) не защищает путь рендера таблицы.

**Рекомендуемый ремонт:** хотя бы memoization labels на время формирования одной страницы, изолированная по target panel/tenant/actor/directory/subject и lookup phase; сбрасывать при новой странице/изменении target и после write. Для разных subjects возможен batch capability, но добавлять публичный `describeMany()` до измерения такой необходимости необязательно. Не делать глобальный кэш directory results: фильтры могут зависеть от actor и текущих полномочий.

**Приёмка:** те же 25 grants одного subject/actor требуют одного subject read на render (либо другого явно записанного малого бюджета); другой actor/tenant/panel не получает чужие labels; после смены target и успешного write labels пересчитываются; orphan cleanup по-прежнему показывает raw identity при отсутствии модели.

## Что проверено и не объявлено дефектом

- **Основной enforcement и queries.** Generic resource authorization централизована, query проходит через `FilamentGate::visible`, отсутствующая definition закрывает список. Unsupported exact adapters явно отклоняются. Выполняется проверка relation manager traits. Не найден отдельный альтернативный allow pipeline.
- **Недоверенные значения редакторов.** Target panel ограничен `manages`; fixed tenant guard panel повторно сверяется; selectable tenant заново описывается directory. Grant ids проходят partition-scoped managers. Locked fingerprints и atomic bulk revoke сохраняют смысл. Наличие admin grant прав на все управляемые панели — осознанно широкий scope `manages`, а не автоматически доказанный privilege escalation.
- **Delegation policy.** Editor ability проверяется в guard panel; authority writer получает actor через `AzGuard::actingAs`, change pipes могут проверять delegation. `manages` сам по себе не является actor policy. Для делегированного администратора требуется явно описанный host contract; в этом срезе не приписывается обещание встроенной гранулярной delegation policy, которого API не даёт.
- **Bulk/custom actions.** Перед callback есть повторный `getAuthorizationResponse`; кастомные bulk actions проверяют выбранные видимые строки; inline editable columns/raw closures запрещены. Нельзя обещать sandbox доверенного PHP, который самостоятельно пишет в DB; это не использовалось для находок.
- **Export.** В enforced panel job заменяется на authorization-aware prepare job, в options передаются panel/tenant/model/permission; chunk заново фильтрует keys через visibility. Отсутствие подходящего authority пишет ноль строк. Рассмотрены существующие tests отзыва grant и неверных integer keys. Полный async worker и tenant/scope экспорт отдельно в этом срезе не запускались.
- **Laravel.** Panel admission и cleanup используют `try/finally`; Gate различает owned/foreign ability; status/message атрибутов используются отдельно от внутренней explanation; persistent middleware настроен по resolved alias. `RouteChecks` использует одно понимание attribute coverage для fallback/strict mode. Не выявлен доказанный дефект middleware/Gate в прочитанном срезе.
- **Long-lived workers.** Request-local authorizer/context bindings и существующие `forgetScopedInstances()` тесты прочитаны. Реальный Octane worker не запускался; тесты прямо оставляют его отдельным неподтверждённым сценарием (`tests/Acceptance/Crm/README.md:90`). Возможные container-clone особенности не представлены здесь как установленная утечка.
- **Настройка/UX.** Grant field extension и schema-driven fields являются полезной узкой точкой расширения. Все package resources/pages final, labels преимущественно зашиты по-английски; это ограничивает white-label/localization, но при отсутствии принятого требования не объявляется дефектом безопасности. Если такая настройка нужна владельцу, её следует отдельно решить до freeze; не разблокировать все internal classes ради гипотетической гибкости.

## Воспроизведение и приёмочный набор

Из корня репозитория:

```bash
env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: AUTHORITY_REPLICA_TEST= \
  php -d memory_limit=1G vendor/bin/phpunit \
  audits/2026-10-15-api-freeze/evidence/filament/FilamentProbeTest.php \
  --colors=never --no-coverage \
  --log-junit audits/2026-10-15-api-freeze/evidence/filament/probes.junit.xml
```

Финальный результат: **5/5 probes, 24 assertions**. Это положительные утверждения о найденном неправильном поведении, не acceptance repaired code. XML сохранён рядом. Во время подготовки исправлялась только обвязка audit probes: Pest не создаёт корректный namespace из числового сегмента `2026-10-15-api-freeze`, поэтому используется обычный PHPUnit test class; production/tests не редактировались. Неуспешные попытки запуска не названы зелёной qualification.

Generator probe:

```bash
php audits/2026-10-15-api-freeze/evidence/filament/enum-marks-probe.php
php -l audits/2026-10-15-api-freeze/evidence/filament/generated-widget-permission.php
```

Ожидаемый текущий результат второго шага: exit 255 с duplicate import. Этот файл намеренно невалиден и является evidence; включать его в production source lint не нужно.

Для последующей реализации достаточно сначала согласовать семантику FIL-01 и полноту inventory FIL-02, затем внести локальные исправления FIL-03/FIL-04 и request-local оптимизацию FIL-05. После ремонта инвертировать probes в основные behavioral tests, запустить `tests/Feature/Filament` и затронутые Gate/HTTP/queue suites, затем общую qualification/API checks по правилам репозитория. Нужды в полном переписывании Filament-пакета по этим находкам нет.
