# 12 — Эксплуатация, релиз, документация

Решения: [D35](02-decisions.md#d35), [D38](02-decisions.md#d38)–[D42](02-decisions.md#d42), [D44](02-decisions.md#d44),
[D51](02-decisions.md#d51).

## 1. Команды

Общие правила:

- все пишущие команды идут через `AzGuard::panel($id)->manage()->asSystem('cli: <команда>')` — ту же проверку, аудит
  и события, что и код;
- у команд, работающих с правами, есть `--panel=`. Если в приложении одна панель, опцию можно не указывать. Если
  панелей несколько и панель не следует из ключа, команда без `--panel` завершается ошибкой, а не выбирает сама;
- `--json` у читающих команд; код выхода ≠ 0 при ошибке; `--force` обязателен для необратимых действий в production.

| Команда | Что делает |
|---|---|
| `azguard:install [--migrate] [--force]` | D40: конфиг, вопросы (соединение, `host_keys`), предложение создать первую панель, список pending-миграций AzGuard, doctor |
| `azguard:doctor [--panel=] [--storage=] [--production] [--json]` | все проверки ядра и плагинов (§2) |
| `azguard:panels:list [--settings] [--contributions] [--json]` | панели; `--settings` — итоговые настройки и откуда взято каждое значение; `--contributions` — что принёс каждый плагин |
| `azguard:upgrade --dry-run \| --execute [--panel-map=] [--panel-for-orphans=] [--superadmin=per-panel\|global-plugin] \| --drop-legacy` | 0.3 → 0.4 ([08 §6](08-data-model-and-migration.md#6-upgrade-03x--040)) |
| `azguard:storage:migration {storage} [--models]` | миграция для именованного хранилища (свой префикс/соединение); `--models` — колонки своих моделей панелей |
| `azguard:storage:move {panel} --to={storage} [--dry-run]` | перенос данных панели в другое хранилище (с новой версией состояния) |
| `azguard:catalog:list [--panel=] [--json]` | каталог: права, откуда пришли (провайдер, плагин), метки |
| `azguard:catalog:cache` / `azguard:catalog:clear` | снимок каталогов и панелей в `bootstrap/cache/azguard.php` |
| `azguard:roles:sync [--panel=] [--dry-run] [--prune]` | code-роли ↔ таблица ролей по `(panel, key)` + `formerKeys()`; `--prune` удаляет роли удалённых классов (через менеджер доступа) |
| `azguard:roles:list [--panel=]` | роли, происхождение, rank, число держателей |
| `azguard:roles:permissions {role} [--set=…] [--add=…] [--remove=…]` | права DB-роли |
| `azguard:roles:assign {subject} {role} [--context=type:id] [--expires=] [--attr=key=value…]` / `azguard:roles:revoke …` | назначения (роль `panel:key` задаёт панель); `--attr` — свои поля |
| `azguard:roles:assign {subject} {panel}:superadmin [--all-panels]` | суперадмин панели; `--all-panels` — во всех панелях, принимающих этот тип субъекта |
| `azguard:grants:issue {subject} {permission} [--context=] [--expires=] [--attr=…]` / `azguard:grants:revoke …` / `azguard:grants:list` | прямые права |
| `azguard:assignments:list {subject} [--panel=]` | всё, что есть у субъекта: роли, права, контексты, сроки, свои поля |
| `azguard:assignments:prune [--panel=] [--before=]` | удаление истёкших (события `AssignmentExpired`) |
| `azguard:changes:pending [--panel=]` | изменения, ожидающие подтверждения (если плагин это поддерживает) |
| `azguard:state:reset {panel} [--force]` | новая версия состояния панели (после ручного вмешательства в БД) |
| `azguard:explain {subject} {permission} [--context=] [--json]` | объяснение решения по шагам ([09 §9](09-authorization-semantics.md#9-объяснение)) |
| `azguard:permissions:show {subject} {panel} [--context=]` | итоговый набор прав |
| `azguard:make:panel`, `azguard:make:permissions`, `azguard:make:role` | генераторы: провайдер панели, enum прав, роль |
| `azguard:make:plugin`, `azguard:make:restriction`, `azguard:make:source` | генераторы: плагин, ограничение, источник прав |
| `azguard:make:models {panel}` | свои модели панели (наследники базовых) + миграция колонок |
| `azguard:filament:generate [--panel=] [--dry-run]` | enum прав Filament-ресурсов |

`{subject}` — `type:id` (`user:42`) или просто id, если панель принимает один тип субъекта.

## 2. Doctor: проверки

Doctor проверяет **каждую панель и каждое хранилище отдельно** и выводит, к чему относится находка. Плагины добавляют
свои проверки (`doctorChecks()`), они выводятся с id плагина.

| Ключ | Откуда | Что проверяет | Важность |
|---|---|---|---|
| `config.normalized` | ядро | устаревшие ключи, конфликты старого и нового | warning / error |
| `storage.schema` | ядро, на хранилище | таблица состояния хранилища = конфиг; реальные типы колонок = `host_keys`; колонки своих моделей есть | error |
| `storage.migrated` | ядро, на хранилище | миграции 0.4 выполнены; legacy-таблицы (есть/удалены) | error / info |
| `panels.valid` | ядро | грамматика id, провайдеры, модели наследуют базовые, хранилище существует | error |
| `panels.plugins` | ядро, на панели | зависимости плагинов, конфликты настроек, плагин подключён, но выключен | error / warning |
| `panels.administration` | ядро | `administeredBy` указывает на существующую панель; нет цепочек | error |
| `catalog.collisions` | ядро, на панели | коллизии ключей между провайдерами и плагинами (с учётом `keyPrefix`) | error |
| `catalog.cached` | ядро | `--production`: снимок каталога есть и свежий | warning |
| `roles.definitions` | ядро, на панели | `definition` разрешается; роли удалённых классов | error |
| `roles.sync` | ядро, на панели | code-роли не синхронизированы с БД | warning |
| `grants.dead` | ядро, на панели | выдачи с ключами вне каталога или с типом контекста, который панель не принимает | warning |
| `superadmin.present` | ядро, на панели | есть суперадмин или актор с `roles.manage` (иначе админ-UI не сможет ничего выдать) | warning |
| `membership.configured` | ядро, на панели | панель требует членства → `ContextMembership` задан | error |
| `consistency.reads` | ядро, на панели | `reads = default` при настроенных read-хостах | warning |
| `cache.store` | ядро, на панели | персистентный store + `ttl = null` | error |
| `gate.mode` | ядро, на панели | `additive` | warning |
| `direct_writes` | ядро | обнаружены прямые записи моделей AzGuard | warning |
| `filament.plugin` | filament | `guardPanel` существует, ключи ресурсов уникальны, нет устаревших enum | error |
| `filament.manages` | filament | каждой панелью из `manages` можно управлять из этой админки (§2 [11](11-filament.md)) | warning |
| `<plugin-id>.*` | плагины | своя конфигурация плагина | как объявит плагин |

## 3. Планировщик и `about`

`azguard.schedule.enabled = true` → `azguard:assignments:prune` по всем панелям по `schedule.prune_expired` (по
умолчанию `daily`). `php artisan about` показывает версию, панели (с хранилищем и числом плагинов), `host_keys`,
cache store, версии состояния панелей.

## 4. Релиз и артефакты

| Тема | Правило |
|---|---|
| Версии | lockstep: один тег монорепо → split-репозитории `azguard`, `azguard-filament`; `branch-alias dev-main: 0.4.x-dev` → `1.0.x-dev` |
| Имена | `axioma-studio/azguard`, `axioma-studio/azguard-filament`; старые пакеты помечаются abandoned |
| Метаданные | `homepage`/`support` — реальный публичный репозиторий ([Q9](15-owner-questions.md)); в `composer.json` нет `version` |
| Consumer-фикстуры | чистый Laravel-проект, установка **собранных** архивов (без path-репозиториев): (a) `azguard` с одной панелью; (b) `azguard` с тремя панелями, своими моделями, модулем и своим плагином; (c) `azguard + azguard-filament`; (d) `azguard` + **пример интеграции** (`fixtures/example-integration`, [10 §7](10-integrations.md#7-совместимость-версий)) |
| Матрица | PHP 8.3/8.4/8.5 × Laravel 11/12/13 × prefer-lowest/stable; SQLite/PostgreSQL 13+/MySQL 8.0/MariaDB 10.6 для схемы и upgrade; Filament 5.x — каждая поддерживаемая minor-линия; Redis-store для кэша |
| Upgrade rehearsal | фикстура 0.3.x (реальная схема + данные всех видов из [08 §6](08-data-model-and-migration.md#6-upgrade-03x--040)) → `azguard:upgrade --execute` → матрица решений до/после |
| Отчёт о качестве | line/branch coverage, mutation score с явным знаменателем, доля кода в mutation scope, список исключённых namespace с причиной (Codex C12) |

## 5. Гейты совместимости

С 0.9.0 каждый PR их проходит, с 1.0.0 — они блокируют:

| Гейт | Инструмент | Что фиксирует |
|---|---|---|
| PHP API | `api-manifest.json` (D12) + с 1.0 Roave BC Check против последнего тега | типы, методы, параметры **со значениями по умолчанию**, константы, enum cases, `final/readonly`, интерфейсы `@api`/`@spi` |
| Панели и плагины | снимок методов `PanelBuilder`, стадий пайплайнов и правил шагов | что можно настроить и в каком порядке исполняется |
| Конфиг-схема | снимок нормализованного конфига по умолчанию + карта нормализатора | ключи, типы, значения по умолчанию |
| События | снимок `EventType` + JSON-формы payload | имена, поля, типы |
| Команды | снимок сигнатур `azguard:*` (аргументы, опции, коды выхода) | CLI |
| Схема БД | снимок DDL после fresh-миграции на трёх СУБД | таблицы, колонки, индексы |
| Грамматика | property-тесты `PermissionGrammar`, `IdentityCodec` | ключи, шаблоны, ссылки |
| Коды исключений | снимок `code()` всех исключений | `snake_case`-коды |
| Семантика | тесты-рецепты из документации + таблицы [09](09-authorization-semantics.md) | поведение решений |
| Интеграции | пример интеграции + `IntegrationContractTests` | контракт [D51](02-decisions.md#d51) |

Каждое изменение снимка требует записи в `CHANGELOG.md` с классом: API / behavior / schema / configuration /
serialization / event / security tightening / docs (как предлагал исходный аудит).

## 6. Документация

Документация пишется **от понятий к деталям** и простым языком; техническая спецификация — в конце разделов.

| Раздел | О чём |
|---|---|
| «Как устроен AzGuard» | панели, права, роли, контексты, плагины, пайплайны — по-человечески (из [00](00-overview.md)) |
| «Панели» | создание, настройки, несколько панелей в одном приложении, `configurePanel`, `administeredBy` |
| «Плагины» | подключение встроенных и своих, написание плагина, `keyPrefix`, зависимости |
| «Пайплайны» | шаги проверки и изменения, что можно и нельзя каждому шагу, подтверждения |
| «Хранилища и свои поля» | именованные хранилища, свои модели, `decisionAttributes`, миграции |
| «Модули» | Laravel modules: своя панель или плагин в чужой |
| «Интеграция вашего пакета» | контракт [10](10-integrations.md), правила, `IntegrationContractTests` |
| «Смысл решений» | из [09](09-authorization-semantics.md) |
| «Гарантии консистентности» | дословная гарантия отзыва |
| «Обновление с 0.3» | upgrade-команда, карта переименований |

- Каждый PHP-пример — исполняемый рецепт (`tests/Recipes/*`); CI проверяет, что сниппеты в `docs/` совпадают с
  рецептами.
- Удаляются: рецепт `Gate::before` для super-admin, «Option 3: direct wildcard grant», утверждение о per-panel
  wildcard; вместо них — рецепты по D19/D26.
- Русская версия `docs/ru` синхронизируется по тем же рецептам (один исходник примеров).

## 7. Тестовый kit для хоста

```php
use AzGuard\Testing\InteractsWithAzGuard;

it('lets store managers cancel orders', function () {
    $fake = AzGuard::fake();
    $user = Customer::factory()->create();
    $this->actingAsWithPermissions($user, ['site.orders.cancel'], context: $store);   // через asSystem('test')
    $this->post(route('orders.cancel', $order))->assertOk();
    $fake->assertChecked('site.orders.cancel');
});
```

`InteractsWithAzGuard` работает и под `RefreshDatabase`: кэш исполняется, обход только при незакоммиченном изменении
AzGuard в том же процессе ([D24](02-decisions.md#d24)).
