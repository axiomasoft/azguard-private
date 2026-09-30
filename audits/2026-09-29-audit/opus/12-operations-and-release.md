# 12 — Эксплуатация, релиз, документация

Решения: [D35](02-decisions.md#d35), [D38](02-decisions.md#d38)–[D42](02-decisions.md#d42), [D44](02-decisions.md#d44),
[D51](02-decisions.md#d51), [D56](02-decisions.md#d56), [D58](02-decisions.md#d58).

## 1. Команды

Общие правила:

- все изменяющие команды идут через тот же пайплайн изменений, что и код; актор — `system` с именем команды;
- у команд, работающих с правами, есть `--panel=`. Без него действует правило выбора панели ([D05](02-decisions.md#d05)):
  полное имя (`admin:manager`) или панель по умолчанию; если панель не определить, команда завершается ошибкой, а не
  выбирает сама;
- У required-tenant commands обязателен `--tenant=type:id`; `--on=` не заменяет tenant. Grants sync/revoke указывает origin (manual default); wildcard всех tenants не добавляется.
- `--json` у читающих команд; код выхода ≠ 0 при ошибке; `--force` обязателен для необратимых действий в production.

| Команда | Что делает |
|---|---|
| `azguard:install [--migrate] [--force]` | D40: конфиг, вопросы (подключение, `host_keys`), предложение создать первую панель, список миграций AzGuard, doctor |
| `azguard:doctor [--panel=] [--storage=] [--production] [--json]` | проверки ядра и плагинов (§2) |
| `azguard:panels:list [--settings] [--sources] [--schema] [--json]` | панели; `--settings` — итоговые настройки и откуда взято значение; `--sources` — источники панели и что принёс каждый источник и плагин; `--schema` — схема прав |
| `azguard:sources:list [--json]` | имена в фабрике источников (`#[AsSource]`, `extend()`), класс, параметры из `config('azguard.sources')`, какие панели используют |
| `azguard:storage:migration {storage}` | миграция для именованного или собственного хранилища (свой префикс, подключение) |
| `azguard:catalog:list [--panel=] [--json]` | права: статичные и динамические, домен, откуда пришли, есть ли политика |
| `azguard:catalog:cache` / `azguard:catalog:clear` | снимок найденного `FolderSource` (enum, политики, роли, `#[AsSource]`) и статичных каталогов в `bootstrap/cache/azguard.php`; вызываются из `php artisan optimize` / `optimize:clear` |
| `azguard:roles:list [--panel=]` | PHP-классы ролей read-only, способы назначения, суперадмин ли, число scoped держателей |
| `azguard:permissions:create {name} [--label=] [--group=]` / `azguard:permissions:delete {name}` | динамические права (панель с `dynamicPermissions`) |
| `azguard:roles:rename-key {role} {new}` | переименовать ключ статичной роли в выдачах (после смены `#[Role]`; прежний ключ — в `#[FormerKeys]`) |
| `azguard:roles:grant {subject} {role} [--on=type:id] [--until=] [--field=key=value…]` / `azguard:roles:revoke …` | выдачи ролей |
| `azguard:permissions:grant {subject} {permission} [--on=] [--until=] [--field=…]` / `azguard:permissions:revoke …` | выдачи прав |
| `azguard:grants:list {subject} [--panel=]` | всё, что есть у субъекта: по панелям, роли, права, сущности, сроки, поля |
| `azguard:grants:prune [--panel=] [--before=]` | удаление истёкших (события `GrantExpired`) |
| `azguard:state:reset {panel} [--force]` | новая версия состояния панели (после ручного вмешательства в БД) |
| `azguard:explain {subject} {permission} [--on=] [--json]` | объяснение решения по шагам ([09 §11](09-authorization-semantics.md#11-объяснение)) |
| `azguard:permissions:show {subject} [--panel=] [--on=]` | итоговый набор прав по панелям |
| `azguard:make:panel {Panel}` | папка панели `app/Guards/{Panel}/`: провайдер, `Roles/`, запись в конфиг — как сегодня `make:guard-panel` |
| `azguard:make:permission {Panel} {Group} [--model=] [--policy] [--abilities]` | enum в `Permissions/{Group}`; опционально policy в `Policies/{Group}` и DTO в `Abilities/{Group}`; заменяет make:guard-domain и make:guard-permission одним генератором |
| `azguard:make:role`, `azguard:make:policy` | отдельные файлы в структуре панели; роль — сразу с `#[Role('<key>')]`; policy использует явный enum либо однозначную группу D56 |
| `azguard:make:source {Name} [--panel=] [--shared]` | источник в `{Panel}/Sources/` или `Shared/Sources/` с `#[AsSource]` и выбранными возможностями (`--grants`, `--permissions`, `--roles`, `--policies`) |
| `azguard:make:plugin`, `azguard:make:restriction`, `azguard:make:pipe` | плагин, ограничение (`Restrictions/`), pipe изменений (`Changes/`) |
| `azguard:stubs` | опубликовать стабы генераторов, как `php artisan stub:publish` |
| `azguard:make:models {panel}` | свои модели `DatabaseSource` в `{Panel}/Models/` (наследники базовых) + миграция колонок |
| `azguard:filament:generate [--panel=] [--dry-run]` | enum прав Filament-ресурсов |

`{subject}` — `type:id` (`user:42`) или просто id, если модель субъекта одна.

## 2. Doctor: проверки

Doctor проверяет **каждую панель и каждое хранилище** и пишет, к чему относится находка. Плагины добавляют свои
проверки (`doctorChecks()`), они выводятся с id плагина.

| Ключ | Откуда | Что проверяет | Важность |
|---|---|---|---|
| `config.valid` | ядро | значения конфига в допустимых списках | error |
| `storage.schema` | ядро, на хранилище | таблица состояния = конфиг; реальные типы колонок = `host_keys`; колонки своих моделей есть | error |
| `storage.migrated` | ядро, на хранилище | миграции выполнены | error |
| `panels.valid` | ядро | id, провайдеры, модели наследуют базовые, хранилище существует, одна панель по умолчанию на модель | error |
| `panels.plugins` | ядро, на панели | зависимости плагинов, конфликты настроек | error |
| `panels.sources` | ядро, на панели | имена источников зарегистрированы; `id()` не повторяются; писатель не больше одного; источники с `ChecksHealth` прошли свои проверки | error |
| `panels.policies` | ядро, на панели | у каждого права не больше одной привязки; сигнатуры методов подходят; автопоиск нашёл то же, что в кэше | error / warning |
| `policies.complete` | ядро, на панели | кейс без метода и без RequiresGrant — error; непривязанный публичный метод — warning | error / warning |
| `roles.keys` | ядро, на панели | нет explicit stable key через `#[Role]` или key() override | error |
| `routes.checks` | ядро, на панели | строгий режим: действия без `#[CheckPermission]`/`azguard.can`, Laravel `can`/`#[Authorize]` и без `#[SkipPermissionCheck]`; `#[CheckPermission]` с правом не своей панели | error |
| `panels.relations` | ядро, на панели | связи существуют на моделях, роли из связей есть на панели | error |
| `catalog.collisions` | ядро, на панели | коллизии имён между источниками и плагинами (с учётом `prefixed`) | error |
| `catalog.cached` | ядро | `--production`: снимок каталога есть и свежий | warning |
| `roles.orphaned` | ядро, на панели | выдачи ролей, которых больше нет в PHP-каталоге; cleanup разрешён | warning / error |
| `grants.dead` | ядро, на панели | выдачи с правами вне каталога, невыдаваемыми правами или непринятым типом сущности | warning |
| `fields.meta` | ядро, на панели | поле из `decisionFields` лежит в `meta` | warning |
| `membership.configured` | ядро, на панели | панель требует членства → членство задано | error |
| `consistency.reads` | ядро, на панели | `reads = default` при настроенных read-хостах | warning |
| `cache.store` | ядро, на панели | постоянный store + `ttl = null` | error |
| `gate.mode` | ядро, на панели | любое значение кроме authoritative | error |
| `direct_writes` | ядро | попытки прямых model/mass writes отклонены; raw SQL требует reconcile | error |
| `filament.plugin` | filament | `guardPanel` существует, ключи ресурсов уникальны, нет устаревших enum | error |
| `<plugin-id>.*` | плагины | своя конфигурация плагина | как объявит плагин |

## 3. Планировщик и `about`

`azguard.schedule.enabled = true` → `azguard:grants:prune` по всем панелям по `schedule.prune_expired` (по
умолчанию `daily`). `php artisan about` (`AboutCommand::add`) показывает версию, панели (источники, хранилище, по
умолчанию ли), именованные источники, `host_keys`, cache store, свежесть кэша каталога, версии состояния панелей.

## 4. Релиз и артефакты

| Тема | Правило |
|---|---|
| Версии | lockstep: один тег монорепо → split-репозитории `azguard`, `azguard-filament`; `1.0.0-beta.N` → `1.0.0`; `branch-alias dev-main: 1.0.x-dev` |
| Имена | `axiomasoft/azguard`, `axiomasoft/azguard-filament`; старые имена `axioma-studio/azguard-*` помечаются abandoned |
| Метаданные | `homepage`/`support` — `github.com/axiomasoft/azguard`; в `composer.json` нет `version` |
| Consumer-фикстуры | чистый Laravel-проект, установка **собранных** архивов (без path-репозиториев): (a) одна панель; (b) кабинет на коде и политиках + админка на БД + кабинет продавца со связями + модуль; (c) `azguard + azguard-filament`; (d) **пример интеграции** (`fixtures/example-integration`, [10 §7](10-integrations.md#7-совместимость-версий)) |
| Матрица | PHP 8.3/8.4/8.5 × Laravel 11/12/13 × prefer-lowest/stable; SQLite/PostgreSQL 13+/MySQL 8.0/MariaDB 10.6; Filament 5.x — каждая поддерживаемая minor-линия; Redis-store для кэша |
| Отчёт о качестве | line/branch coverage, mutation score с явным знаменателем, доля кода в mutation scope, исключённые namespace с причиной (Codex C12) |

## 5. Гейты совместимости

С первого beta каждый PR их проходит, с 1.0.0 — они блокируют:

| Гейт | Инструмент | Что фиксирует |
|---|---|---|
| PHP API | `api-manifest.json` (D12) + с 1.0 Roave BC Check против последнего тега | типы, методы, параметры **со значениями по умолчанию**, константы, enum cases, `final/readonly`, интерфейсы `@api`/`@spi` |
| Трейт | снимок методов `HasAzGuard` и `SubjectAccess` | повседневный синтаксис |
| Панели | снимок методов `PanelBuilder` и шагов пайплайнов | что можно настроить и в каком порядке исполняется |
| Схема панели | JSON-снимок `PanelSchema::toArray()` фикстуры | форма данных для UI |
| Конфиг | снимок конфига по умолчанию | ключи, типы, значения |
| События | снимок `EventType` + JSON-формы payload | имена, поля, типы |
| Команды | снимок сигнатур `azguard:*` | аргументы, опции, коды выхода |
| Схема БД | снимок DDL после миграции на трёх СУБД | таблицы, колонки, индексы |
| Грамматика | property-тесты `PermissionGrammar`, `IdentityCodec` | имена, шаблоны, ссылки |
| Коды исключений | снимок `code()` всех исключений | `snake_case`-коды |
| Семантика | тесты-рецепты из документации + таблицы [09](09-authorization-semantics.md) | поведение решений |
| Интеграции | пример интеграции + `IntegrationContractTests` | контракт [D51](02-decisions.md#d51) |

Каждое изменение снимка требует записи в `CHANGELOG.md` с классом: API / behavior / schema / configuration /
serialization / event / security tightening / docs.

## 6. Документация

Документация пишется **от понятий к деталям**, простым языком; техническая спецификация — в конце разделов.

| Раздел | О чём |
|---|---|
| «Как устроен AzGuard» | панели-конструкторы, источники, папка панели, роли, сущности, суперадмин — по-человечески (из [00](00-overview.md)) |
| «Быстрый старт» | одна панель, трейт, `hasPermission`, `grantRole` — как в Spatie |
| «Если вы пришли из Spatie Permission» | соответствие методов, чем отличаются панели и сущности |
| «Панели» | создание, панель по умолчанию, middleware входа, несколько панелей у одной модели, `configurePanel` |
| «Папка панели» | домены, enum, политики, роли, атрибуты; `Shared/`; генераторы; кэш каталога |
| «Источники» | `FolderSource`, `DatabaseSource`, `RelationSource`, `GateSource`; свой источник и фабрика (`#[AsSource]`, `extend()`); как переносить право между источниками |
| «Два уровня проверки» | выдачи и политика того же права; таблица «политика вернула → итог»; рабочие часы, «своё — всегда» |
| «Хуки, pipes и события» | `before`/`after` как у Gate, ограничения, pipes `changing` как у Pipeline, Laravel-события; рецепты (подтверждение, «не больше своего», срок по умолчанию) |
| «Маршруты и контроллеры» | `azguard.panel`, `#[CheckPermission]` (наследник Laravel `#[Middleware]`), `#[SkipPermissionCheck]`, строгий режим |
| «Схема панели и свой интерфейс» | `PanelSchema`, Filament, свой UI на Inertia/Vue |
| «База данных и свои поля» | настройки `DatabaseSource`: хранилища, свои модели, `azguardFields`, `meta`, `decisionFields`, динамические права |
| «Модули и плагины» | своя панель модуля или плагин в чужой, `prefixed` |
| «Интеграция вашего пакета» | контракт [10](10-integrations.md), правила, `IntegrationContractTests` |
| «Смысл решений» и «Гарантии консистентности» | из [09](09-authorization-semantics.md), дословная гарантия отзыва |

- Каждый PHP-пример — исполняемый рецепт (`tests/Recipes/*`); CI проверяет, что сниппеты в `docs/` совпадают с
  рецептами.
- Удаляются: рецепт `Gate::before` для super-admin, «Option 3: direct wildcard grant», утверждение о per-panel
  wildcard.
- Русская версия `docs/ru` синхронизируется по тем же рецептам.

## 7. Тестовый kit для приложения

```php
use AzGuard\Testing\InteractsWithAzGuard;

it('lets store managers cancel orders', function () {
    $fake = AzGuard::fake();
    $user = User::factory()->create();
    $this->actingAsWithPermissions($user, ['seller:orders.cancel'], on: $store);
    $this->post(route('seller.orders.cancel', $order))->assertOk();
    $fake->assertChecked('seller:orders.cancel');
});
```

`InteractsWithAzGuard` работает и под `RefreshDatabase`: кэш исполняется, обход только при незакоммиченном изменении
AzGuard в том же процессе ([D24](02-decisions.md#d24)).


## 8. Эксплуатация tenant scope и честная матрица

Doctor дополняется: contexts.registered/roles.contexts, tenants.membership, resources.scope,
visibility.exact, sources.authority/origins, identity.domains/canonical, storage.physical_identity,
state.incarnation/fresh_reads, gate.ownership/order, dynamic.collisions, imports.partial_revision.
Некоторые нарушения проверяются статически, остальные — fixture/records sampling; doctor не объявляет proof
всех внешних отношений. Secrets из sources config маскируются. State reset меняет incarnation на primary.

Matrix строится по реально разрешимым Composer constraints: core может иметь несколько Laravel версий,
Filament проверяется только с разрешёнными им PHP/Laravel; `self.version` требует CI consumer архивов.
Min/latest каждой выбранной DB и supported Filament release воспроизводятся в CI до объявления поддержки.
SQLite не доказывает FOR UPDATE/deadlock/replica semantics PG/MySQL. Read/write replication, внешние
source outages, Octane/queue и cross-connection listing проверяются соответствующими consumers (V99–V105).

Boot caches содержат только static definitions/build id, tenant dynamic catalog overlays читаются отдельно.
Rolling deploy обязан выявлять collision static/dynamic keys до перехода readers; старые workers завершаются,
cache namespace обновляется. Схема хранения, aliases и кодек не меняются silent runtime configuration.
Audit run baseline/probes из evidence — исторический факт, не текущий зелёный release gate.

## 6. Реальная приёмка возможностей

Перед stable release обязательна [CRM acceptance suite](17-crm-acceptance-tests.md): R01–R68,
реальные модели/storage/query/HTTP/UI/plugins/workers/consumer fixtures и qualification matrix.
Report с actual statuses/SQL/trace/engine variants; Markdown/SQLite model green не равно готовности пакета.
Doctor additions: context recipes/identity/profile schemas/options validity; callback input type/null contract;
unsupported query shapes; plugin instance/build/runtime isolation; current guard selector и trait method conflicts;
cache recipe recoverability/build id/secret refs. Conditions поля role включены в decisionFields projection.


D80–D83: отсутствуют CLI/UI CRUD definitions ролей и profile registry. Enum rights работают с DB assignments без
flag dynamicPermissions; PolicyOnly assignment rejected. Dynamic actions opt-in, mode Grants immutable.
Doctor проверяет role class catalogue keys, typed model/filter/plugin contracts, authority/policy ownership,
missing code bindings/active build fingerprints; PolicyOnly access не требует здоровой assignment DB.
Readiness: R01–R68 + V117–V120, [19](19-oop-and-permission-authority.md), [20](20-process-map.md).
