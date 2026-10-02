# P2 execution evidence

**Plan:** `2026.10.01-№1-AZGUARD-V1` · **Run:** `plan-run P2.1 P2.2 P2.3` (batch B2a, session `6db62b44-6007-457b-adf9-c5eabdb657ed`)

## P2.1 — PanelProvider, PanelBuilder, Panel, PanelRegistry, CurrentPanel (2026-10-02)

### Метод builder → запись рецепта → тест

Каждый setter пишет одну запись `{setting, value, origin}` в `PanelRecipe` и возвращает builder. Тест — строка
dataset `writes every setter call to the recipe with the provider origin and returns the builder`
(`tests/Unit/Panels/PanelBuilderTest.php`); колонка «Проверка входа» — отдельные тесты того же файла.

| Метод | Запись (`PanelRecipe::*`) | Значение | Проверка входа |
|:--|:--|:--|:--|
| `id` | `id` | строка | ≠ `getId()` → `DefinitionException` (`rejects an id that differs…`) |
| `label` / `description` | `label` / `description` | строка / `?string` | — |
| `default` | `default` | bool | — |
| `resourcePrefix` | `resource_prefix` | `true` \| `false` \| сегмент | не сегмент → `DefinitionException` (6 значений) |
| `for` | `subjects` | список `{model, guard, directory}` | не-`Model`, пустой и keyed список, пустой guard → `DefinitionException` (6 значений); вызовы складываются |
| `middleware` | `middleware` | список имён | не строка → `DefinitionException` |
| `entry` / `onDenied` / `requireRouteChecks` | `entry` / `on_denied` / `require_route_checks` | как передано / `true` | читает P6.2 |
| `permissions` | `permissions` | список: FQCN enum \| `Source` \| имя | 10 отклоняемых значений → `DefinitionException`; enum один раз по FQCN |
| `roles` | `roles` | список FQCN | не наследник `BaseRole` → `DefinitionException`; класс один раз |
| `presentation` | `presentation` | массив | слияние по ключам — P2.3 |
| `before`, `restrictions`, `after`, `changing`, `grantConditions`, `doctorChecks` | одноимённые списки | class-string \| объект \| closure | не строка и не объект → `DefinitionException`; контракт элемента — P4.1/P5.2/P6.4 |
| `tenantResolvers`, `scopeResolvers` | `tenant_resolvers`, `scope_resolvers` | объект или класс своего SPI | чужой SPI → `DefinitionException` |
| `resourceScopes` | `resource_scopes` | список `{resource, resolver}` | ключ не `Model`, чужой SPI → `DefinitionException` |

Происхождение: `PanelRecipe::provider()` / `plugin(id, order, prefix)` / `configure()`; текущее задаёт
`PanelRecipe::during(origin, fn)` с восстановлением в `finally`. Списки читаются по слоям `provider → плагины по
порядку подключения → configure` (`items()`), scalar — «старший слой, внутри слоя последняя запись»
(`PanelCompiler::scalar()`); конфликт плагинов и значения по умолчанию из конфига добавляет P2.3.

### Решения исполнения

| Вопрос | Решение |
|:--|:--|
| Строка в `permissions([...])` | существующий enum → обязан быть string-backed; существующий класс/интерфейс/trait или имя с `\` → `DefinitionException` (05 §4: «неизвестный/неподходящий класс отклоняется»); иначе — имя источника, его разрешит P2.7 |
| Где регистрируются провайдеры из конфига | в `boot()` провайдера ядра (04 §5 п.2) через `$app->register()`; `PanelProvider::register()` сам пишет себя в реестр, поэтому повторной записи нет |
| Экземпляр провайдера при сборке | зарегистрированный в приложении экземпляр ровно этого класса, иначе `new $class($app)` (панель, добавленная прямым `register()`/`replace()` реестра) |
| Сборка и заморозка | один шаг `PanelRegistry::freeze()` в `$app->booted()`; сбой сборки оставляет реестр без панелей и не замороженным; повторный вызов — no-op |
| `configure(id)` неизвестной панели | `UnknownPanelException` при сборке, до вызова провайдеров |
| Две `default()` | конфликт, если модели совпадают или одна наследует другую (та же семантика `instanceof`, что у `Panel::accepts`) |
| `Panel::accepts(SubjectRef)` | `type()` сравнивается с `getMorphClass()` экземпляра модели; без morph map alias не совпадёт с FQCN |
| Label по умолчанию | id панели |
| Отпечаток | `id`, `prefix`, `default`, `subjects`, `enums`, `roles`, `sources` (`{class, id}` или `{name}`), списки хуков и резолверов (имя класса или `closure`), `resource_scopes`; ключи сортируются, списки сохраняют порядок; `label`/`description`/`presentation` не входят |
| Доступ к рецепту и отпечатку | внутренние `PanelRegistry::recipe(id)`, `fingerprint(id)` реализации (не в контракте) — их читают P2.2 и P2.5 |
| `AzGuardConfig` | читает только `azguard.panels`; неизвестный ключ секции, не-список и не-строка → `InvalidConfigurationException`; класс из списка, не являющийся `PanelProvider`, → `InvalidConfigurationException` в провайдере ядра |

### Наблюдение для P2.6 и Review P2

`replace()` панели, перечисленной в `azguard.panels.providers`, работает из `boot()` провайдера, загруженного после
провайдера ядра (провайдеры приложения — всегда), и не работает из `register()`: провайдеры конфига попадают в реестр
в `boot()` ядра (04 §5). Перенос регистрации в `register()` ядра сломал бы Testbench-сценарий потребителя: там конфиг
теста задаётся после `register()` провайдеров. `configure`/`configureAll` порядка не требуют.

### Доказательство RED arch-правил (scratch-копия, не рабочее дерево)

Копия репозитория в scratchpad (`rsync` без `.git`, `plans`, `audits`, `legacy`, `docs`) + три probe-класса:

| # | Probe | Упавшее правило |
|:--|:--|:--|
| 1 | `Panels\PanelProbe` использует `Storage\StorageProbe` | `panels, catalog, scopes, policies and schema stay off storage and changes (AzGuard\Panels)` — «Expecting 'AzGuard\Panels' not to use 'AzGuard\Storage'» |
| 2 | `Configuration\ConfigurationProbe` использует `Panels\Panel` | `configuration depends only on the kernel, exceptions and the framework (AzGuard\Configuration)` — «…not to use 'AzGuard\Panels'» |

`vendor/bin/pest tests/Arch/ZonesArchTest.php` в копии: 44 теста, 2 failed (ровно эти два); в рабочем дереве — GREEN.

### Diff манифеста ядра

`+` `Contracts\Panels\PanelRegistry` (`@api`, 10 методов), `Contracts\Sources\Source` (`@spi`, `id`),
`Panels\{Panel,PanelBuilder,PanelProvider}` (по расположению; `Panel` — 6 методов и конструктор, `PanelBuilder` —
22 метода таблицы D8 п.2 и конструктор с `PanelRecipe`), шесть исключений; `~` `DefinitionException`: `abstract`
`true → false`. Внутренние `PanelRecipe`, `PanelCompiler`, `PanelFingerprint`, `CurrentPanel`, реализация
`PanelRegistry`, `AzGuardConfig` в манифест не попали.

### Validation

| # | Carrier | Result |
|:--|:--|:--|
| 1 | `vendor/bin/pest tests/Unit/Panels tests/Feature/Panels tests/Unit/Exceptions` | GREEN: 166 passed |
| 2 | `vendor/bin/pest tests/Arch` | GREEN: 53 passed |
| 3 | `php bin/api-manifest.php --check` после `composer api:manifest` | exit 0 |
| 4 | `composer test` | GREEN: 661 passed |
| 5 | `vendor/bin/pint --test` | GREEN |
| 6 | `vendor/bin/phpstan analyse --memory-limit=1G` | GREEN: 0 errors |
| 7 | `php -d memory_limit=1G vendor/bin/pest --type-coverage --min=98` | GREEN: 100.0 %, 77 файлов из 77 |
| 8 | `git diff --check` | clean |

Окружение: PHP 8.3.35, Laravel 13.34.0, Testbench 11.3.0, Pest 4.7.8, PHPStan 2.2.16; `vendor/` установлен заново
(`composer install`, `composer.lock` не отслеживается).

## P2.2 — PanelResolver: одно правило выбора панели и префиксы (2026-10-02)

Спецификация исполнена с поправками [D9](../decisions/D9-p2-owner-amendments.md): `PermissionKey::prefixed()` не
создаётся (решение владельца), `PanelRegistry::replace()` применяется при сборке. Допуск: остаток батча `P2.2 P2.3`
как новый workset (правка спецификаций сменила `spec_digest`), маршрут — сохранённое route evidence сессии.

### Матрица сигналов → исход

`PanelResolver::resolve(subject, permission, panel)` возвращает `{panel, key, step}`. Явные сигналы собираются как
«сигнал → допустимые панели», затем пересекаются:

| Сигнал | Допустимые панели |
|:--|:--|
| аргумент панели | названная (незарегистрированная → `UnknownPanelException`) |
| полное имя `x:local` | `x` (незарегистрированная → `UnknownPanelException`) |
| имя, первый сегмент которого — префикс панели | панель префикса |
| enum | панели, к которым он подключён (ни одной → `UnknownPermissionException`) |

| Пересечение | Исход |
|:--|:--|
| пусто | `ConflictingPanelException` с перечнем сигналов; панель не выбирается и текущая не меняется |
| больше одной (enum нескольких панелей без другого сигнала) | `AmbiguousPanelException` |
| одна | панель, шаг `explicit` |
| сигналов нет | панель запроса, если принимает субъект (`current`) → `azguardDefaultPanel()` / `default()` / единственная панель модели (`model`) → `PanelNotResolvedException` с подсказкой |
| выбранная панель не принимает субъект | `SubjectNotAcceptedException` |

Тесты: `tests/Feature/Panels/PanelSelectionMatrixTest.php` — 270 строк (3 субъекта × 5 форм права × 3 явных × 3
текущих × 2 default) против табличного ожидания, написанного без resolver, плюс 13 закреплённых исходов;
`tests/Unit/Panels/PanelResolverTest.php` — каждое правило отдельно, `SubjectRef` через morph map, `owner()`.

Ключ: enum → `value`; полное имя → как есть (префиксы не применяются); иначе префикс выбранной панели снимается —
`orders.view`, `admin.orders.view` и `admin:orders.view` дают один `PermissionKey`. Существование действия в каталоге
resolver не проверяет.

Владение ability (`owner()`): одно слово → `null`; `x:…` → панель `x` или `null`; префикс → его панель; прочее
имя с точкой → кандидат шагов 2–3 или `null`. Запросов к БД нет: индексы «префикс → панель» и «enum → панели»
строятся при сборке (`PanelRegistry::forPrefix()`, `forEnum()`).

### Что принимают следующие пункты

| Пункт | Что добавляет |
|:--|:--|
| P2.3 | итоговый префикс после разрешения настроек (`defaults.resource_prefix`) — проверка `prefixes()` читает `Panel::prefix()` и менять её не нужно |
| P2.5 | конфликт префикса с первым сегментом локального имени; «имя есть в каталоге кандидата» для `owner()` |
| P2.8 | enum, найденные в папке панели, — через `PanelRegistry::attachEnums()`; префикс плагина внутри префикса панели |
| P4.9, P4.11, P5.1, P6.1, P6.2, P6.6 | строки V64 своих входов (`decideMany`, Gate, трейт и `SubjectAccess`, фасад, middleware, CLI) — каждый идёт через resolver; arch-правило не даст выбрать панель иначе |
| P4.6 | членство субъекта в tenant (resolver проверяет только модель субъекта) |

`SubjectRef` без morph map не даёт панель модели (шаг 3): alias ссылки сопоставляется с моделью через
`Relation::getMorphedModel()`. `azguardDefaultPanel()` читается только у экземпляра модели.

### Замена панели при сборке (D9 п.2)

`replace()` запоминает замену; `freeze()` подставляет её на место зарегистрированного провайдера. Тесты:
`applies a replacement when the panels are compiled, whatever the order of register and replace`,
`lets the last replacement of a panel win`, `reports a replacement of a panel nobody registered when the panels are
compiled` (unit), `lets a module replace a configured panel from its register method`, `fails the boot when a module
replaces a panel nobody registered` (Testbench).

### Arch-правило и доказательство RED

`tests/Arch/SourceConventionsTest.php`: token-скан `packages/*/src` — вызовы `defaultFor()`, `forModel()`,
`azguardDefaultPanel()` и упоминание класса `CurrentPanel` разрешены только в `Panels\PanelResolver`, реестре,
самом `CurrentPanel` и провайдере ядра. Самопроверка сканера: объявление метода, строка и комментарий — не
использование. RED на scratch-копии: `packages/core/src/Laravel/GateProbe.php` с `$this->registry->defaultFor(…)`
→ `picks a panel only in the panel resolver` упал с `packages/core/src/Laravel/GateProbe.php: defaultFor`.

### Регрессии

| Probe | Status |
|:--|:--|
| P01c | `covered: tests/Regression/P01cTest.php::resolves a key of the admin panel to admin and never to the default panel` |
| P05 | `covered: tests/Regression/P05Test.php::throws for an unqualified key without a panel and resolves it with an explicit panel` |
| P09 | `covered: tests/Regression/P09Test.php::picks the panel of a full name without a current panel and throws for a local name` |

Сквозные сценарии spec (трейт, Gate, выдачи) остаются acceptance адаптеров — записано в каждом spec под `Status`.

### Diff манифеста ядра

`+` `PanelNotResolvedException`, `ConflictingPanelException`, `AmbiguousPanelException`, `PrefixConflictException`
(родитель `DefinitionException`), `UnknownPermissionException` (родитель `ChangeException`). Контракт
`PanelRegistry` не изменился по сигнатурам; `PanelResolver` внутренний и в манифест не входит; `PermissionKey` не менялся.

### Validation

| # | Carrier | Result |
|:--|:--|:--|
| 1 | `vendor/bin/pest tests/Unit/Panels tests/Feature/Panels tests/Unit/Kernel/Identity tests/Regression` | GREEN: 644 passed, включая `RegressionSpecsTest` для P01c, P05, P09 |
| 2 | `vendor/bin/pest tests/Arch` | GREEN: 55 passed |
| 3 | `php bin/api-manifest.php --check` после `composer api:manifest` | exit 0 |
| 4 | `composer test` | GREEN: 1011 passed |
| 5 | `vendor/bin/pint --test` | GREEN |
| 6 | `vendor/bin/phpstan analyse --memory-limit=1G` | GREEN: 0 errors |
| 7 | `php -d memory_limit=1G vendor/bin/pest --type-coverage --min=98` | GREEN: 100.0 %, 83 файла из 83 |
| 8 | `git diff --check` | clean |

## P2.3 — Настройки панели и PanelSettings (2026-10-02)

Допуск: `P2.3` как новый workset — receipt остатка батча стал `BATCH_STALE`, потому что он привязан к digest
`handoff.md`, а закрытие P2.2 переписало handoff. Маршрут — сохранённое route evidence сессии.

### Настройка → слои → тест

Scalar-настройку разрешает `PanelCompiler::pick()`: слой `provider` (включая `configure(id)`) → плагины → `configure`
(`configurePanels`) → `default`; внутри слоя побеждает последняя запись.

| Настройка (`PanelSettings::*`) | Builder | Ключ `defaults` | Проверка при сборке |
|:--|:--|:--|:--|
| `resource_prefix` | `resourcePrefix()` | `resource_prefix` (bool) | `true` → id панели, `false` → без префикса |
| `gate.mode` | `gate()` | `gate.mode` | вне `GateMode` → `invalid_configuration.enum` |
| `cache.store` | `cache(store:)` | `cache.store` | store задан, ttl `null` → `invalid_configuration.cache_ttl` |
| `cache.ttl` | `cache(ttl:)` | `cache.ttl` | `< 1` → `invalid_configuration.enum` |
| `cache.generation` | `cache(generation:)` | `cache.generation` | `< 1` → `invalid_configuration.enum` |
| `consistency.reads` | `consistency()` | `consistency.reads` | вне `Reads` → `invalid_configuration.enum` |
| `consistency.state_refresh` | `consistency()` | `consistency.state_refresh` | вне `StateRefresh` → `invalid_configuration.enum` |
| `trace_decisions` | — | `trace_decisions` (bool) | только конфиг, происхождение всегда `default` |

| Что проверено | Тест |
|:--|:--|
| V47: только конфиг → `default`; `configurePanels` → `configure`; запись плагина → `plugin:<id>`; провайдер → `provider`; все четыре → провайдер | `tests/Feature/Panels/PanelSettingsPrecedenceTest.php` — `takes a setting from the highest layer that sets it` (6 строк) |
| два плагина, разные значения → `plugin_conflict`; провайдер задал → без ошибки; одинаковые значения → без ошибки, происхождение — первый плагин | `reports two plugins that set one setting to different values`, `lets the provider settle…`, `accepts plugins that agree…`, Feature `reports a conflict of two plugins unless the provider sets the value` |
| `null` в `cache()` — «не задано на этом слое» | `treats a null cache argument as not set on this layer` |
| значения вне enum и диапазона, store без ttl (8 случаев) | `rejects a value outside its enum or range when the panel is compiled` |
| `origin()` неизвестной настройки → `DefinitionException` | `rejects the origin of a setting that does not exist` |
| списки не заменяются слоем выше; порядок `provider`, плагины, `configure`; дубль class-string схлопывается | `keeps the items of every layer in a list and collapses a class named twice`, Feature `keeps list items of the provider and of configure for all panels in layer order after a boot` |
| `presentation`: провайдер побеждает по ключу, остальные ключи плагина и `configure` сохраняются; конфликт плагинов по ключу | `merges presentation by key…`, `reports two plugins that disagree on a presentation key` |
| префикс: `defaults.resource_prefix = false` → `Panel::prefix()` null; `resourcePrefix('backoffice')` побеждает; словарь префиксов читает итог | `resolves the prefix from the configuration default…` (5 строк), Feature `turns the prefix off for every panel from the configuration…` |
| итоговые настройки входят в отпечаток, `label`/`presentation` — нет | `makes the effective settings part of the panel fingerprint` |
| конфиг: неизвестные ключи `defaults` и групп, типы, целые из env строкой | `tests/Unit/Configuration/AzGuardConfigTest.php` |

Слой плагинов проверен на записях рецепта с происхождением `plugin` (`PanelRecipe::during(PanelRecipe::plugin(id,
order), …)`); сквозной сценарий через `plugins([...])` повторяет P2.4.

### D45 «не настраивается» → чем подтверждено

| Гарантия | Подтверждение |
|:--|:--|
| ни одного метода builder, отключающего проверки | `gives the builder no method that turns a guarantee off`: среди публичных методов нет имён `without…`, `disable…`, `skip…`, `allow…`, `permit…`, `unsafe…`, `bypass…`, `ignore…`; манифест `PanelBuilder` — 25 методов |
| ни одного ключа конфига под гарантию | `defaults` принимает только `resource_prefix`, `gate`, `cache`, `consistency`, `trace_decisions`; `strict_writes`, `fail_closed`, `restrictions`, `gate.enabled` внутри `defaults` → `InvalidConfigurationException` (`rejects a key it does not know`) |
| `gate.mode` — только `authoritative` | `GateMode` имеет один case; `permissive` → `invalid_configuration.enum` |
| `PanelSettings` без замыканий и объектов | `holds plain values only`: `toArray()` — скаляры и пары «значение, происхождение» |

Остальные гарантии D45 (integrity tenant/resource, точные scope/origin записей, fail-closed, атомарность «запись +
версия», сроки выдач, «ошибка = отказ», проверка каталога при выдаче) реализуют P3–P5; здесь закреплено только то,
что для них нет ни метода, ни ключа.

### Решения исполнения и отклонения от `Files`

- Значения по умолчанию при отсутствии ключа в конфиге — `PanelSettings::DEFAULTS` (одно место): `mergeConfigFrom`
  сливает только верхний уровень, приложение с частичной секцией `defaults` получает остальное отсюда.
  `AzGuardConfig::defaults()` отдаёт только заданные ключи; enum-значения остаются строками (зона `Configuration`
  не зависит от `Panels`), проверяет их компилятор.
- Конфиг читается лениво, при сборке панелей: компилятор получает замыкание. Иначе модуль, поднявший реестр из
  своего `register()`, зафиксировал бы конфиг до того, как Testbench потребителя его задал.
- Целые из окружения (`'3600'`) принимаются как числа.
- Изменены два файла вне `Files` пункта: `packages/core/src/Exceptions/InvalidConfigurationException.php` (код с
  уточнением после точки требует Implementation Rules) и `packages/core/src/AzGuardServiceProvider.php` (передача
  `defaults` компилятору — без этого секция конфига не доходит до панелей). Перечень `Files` в спецификации неполон.
- Имена настроек — константы `PanelSettings` (публичные, их принимает `origin()`); `PanelRecipe` не менялся.

### Diff манифеста ядра

`+` `Panels\{PanelSettings,GateMode,Reads,StateRefresh}` (`@api`), `PluginConflictException`; `~` `PanelBuilder`:
`+cache`, `+gate`, `+consistency`; `~` `Panel`: `+settings`, конструктор принимает `PanelSettings` вместо префикса;
`~` `InvalidConfigurationException`: `+failing`.

### Validation

| # | Carrier | Result |
|:--|:--|:--|
| 1 | `vendor/bin/pest tests/Unit/Panels tests/Feature/Panels tests/Unit/Configuration` | GREEN: 544 passed |
| 2 | `vendor/bin/pest tests/Arch` | GREEN: 55 passed, `config('azguard…')` читается только в `Configuration\` |
| 3 | `php bin/api-manifest.php --check` после `composer api:manifest` | exit 0 |
| 4 | `composer test` | GREEN: 1074 passed |
| 5 | `vendor/bin/pint --test` | GREEN |
| 6 | `vendor/bin/phpstan analyse --memory-limit=1G` | GREEN: 0 errors |
| 7 | `php -d memory_limit=1G vendor/bin/pest --type-coverage --min=98` | GREEN: 100.0 %, 88 файлов из 88 |
| 8 | `git diff --check` | clean |

## P2.4 — Плагины: typed factories, PluginContext, изоляция, конфликты (2026-10-02)

Run: `plan-run P2.4 P2.5 P2.6` (batch B2b, session `a1cb8702-ed75-44c2-aedf-e95bd97317b0`), run id
`9a2e2a488d5afd9e63956f430489c8293d5dda107d52d0fbb33b7eea3baecab3`. Работа идёт в worktree-ветке
`worktree-azguard-v1-p2-b2b`; решение D10 и приведённые к нему спецификации закоммичены до старта пункта (`cb18240`).

### Порядок фаз сборки панели

1. `PanelRegistry::freeze()` (на `booted`): эффективные классы провайдеров (с учётом `replace()`), один build id на сборку —
   `AzGuardConfig::buildId(классы провайдеров)`.
2. Для каждой панели запись рецепта: `PanelProvider::panel()` и `configure(id)` (слой `provider`) → `configureAll`
   (слой `configure`).
3. `PanelCompiler::register()`: плагины слоя `provider` в порядке подключения → плагины слоя `configure`, кроме названных
   панелью в `withoutPlugins()` → плагины, подключённые плагинами в `register()` (в порядке записи), повторно до исчерпания.
   Каждый плагин — `clone` объекта (объект из контейнера тоже клонируется); на время `register()` происхождение записей —
   `plugin:<id>` с порядковым номером подключения.
4. Рецепт запечатывается; проверка зависимостей по итоговому набору плагинов панели.
5. `PanelCompiler::compile()` → `Panel` (в том числе `pluginIds()`), отпечаток, индекс enum; затем проверки набора панелей.
6. Реестр заморожен → `PanelCompiler::boot()`: `boot(Panel, PluginContext)` каждого плагина в порядке подключения.

### Сценарий → тест

| Что проверено | Тест |
|:--|:--|
| V48: отсутствующая зависимость → `plugin_dependency_missing` с id плагина, панели и зависимости; реестр не заморожен | `tests/Feature/Plugins/PluginLifecycleTest.php` — `fails the build when a plugin requires a plugin the panel does not have` (2 строки) |
| V48: изменение панели из `boot()` → `RegistryFrozenException`, панель прежняя | `refuses a change of the panel from boot() and leaves the compiled panel as it was` |
| V48: перестановка плагинов меняет отпечаток; тот же порядок — тот же отпечаток | `makes the plugins and their order part of the panel fingerprint` |
| V48: один объект плагина на двух панелях получает два `PluginContext::panelId()` | `tests/Feature/Plugins/PluginIsolationTest.php` — `shows one plugin object its own panel on every panel it is attached to` |
| V85: `make()` через `app()->makeWith(...)` получает привязку контейнера | `tests/Unit/Plugins/BasePluginTest.php` — `creates a plugin through the container, so a binding of its service applies` |
| V85: `retention(30)` и `retention(90)` на разных панелях не делят состояние; состояние из `register()` не видно другой панели; singleton контейнера клонируется | `keeps different settings of one plugin on different panels apart`, `does not show one panel what a plugin kept while it registered on another`, `copies a plugin the container shares…` |
| V85: плагин добавляет `Source`, ограничение и pipe; записи несут `plugin:<id>` и номер подключения | `writes what a plugin adds with the origin of that plugin` |
| V47 (сквозной): два плагина с разным `cache(ttl:)` → `plugin_conflict` с id обоих; провайдер задал ttl → без ошибки; `origin()` → `plugin:<id>`; плагин выше `configurePanels()` и конфига | `tests/Feature/Plugins/PluginSettingsConflictTest.php` (4 теста, 5 строк) |
| V115/V118: у `BasePlugin` нет `make`/`options`/`withOptions`/`prefixed`/`prefix`, конструктора и свойств; `PrefixesKeys` не существует; у `PluginContext` нет `namespace`, опций и контейнера | `is an abstract lifecycle base…`, `declares the plugin lifecycle and dependencies as the only plugin contracts`, `tests/Unit/Plugins/PluginContextTest.php` (3 теста) |
| V115/V118: `make` фикстур — именованные типизированные параметры; `CrmModels` с не-`Model` и не-`Authenticatable` → исключение до сборки панели | `gives every fixture plugin its own factory with named typed parameters`, `rejects a model that does not fit before any panel is built`, `rejects a factory argument of the wrong contract` |
| порядок: провайдер → `configurePanels()` → вложенные до исчерпания; `register` всех панелей раньше любого `boot`; `boot` после заморозки | `registers provider plugins, then plugins for all panels…`, `registers the plugins of every panel in attachment order and boots them once the registry is frozen` |
| `withoutPlugins()`: убирает плагин `configurePanels()`, неизвестный id не ошибка, свой плагин панели остаётся (замена общего плагина своим) | `keeps plugins for all panels off a panel…`, Isolation `lets a panel replace a plugin for all panels with its own settings` |
| два плагина с одним id → `PluginConflictException` (5 способов получить повтор) | `rejects two plugins with one id on a panel` |
| id плагина: грамматика D8 п.5, 128 байт (15 строк) | `accepts a plugin id of the documented form only` |
| элемент `plugins([...])` — объект или класс `Plugin`; иное → `DefinitionException`; контейнер вернул не плагин | `tests/Unit/Panels/PanelBuilderTest.php` — `rejects anything but plugin objects and plugin classes in plugins()`, Lifecycle `rejects a class the container does not resolve to a plugin` |
| происхождение без `prefix` (D10 п.3): форма `{kind, plugin, order}`, `PanelRecipe::plugin(id, order)` | `records the origin that is current when a setter is called` |
| build id: значение конфига; иначе sha256 по парам «класс провайдера → sha256 файла», не зависит от порядка, известен в `register()` | `tests/Unit/Configuration/AzGuardConfigTest.php` (3 теста), Lifecycle `tells plugins the configured build id while they register`, `derives a stable build id…` |
| сквозь загрузку приложения | `runs the plugin lifecycle when the application boots` |

### Решения исполнения и отклонения от `Files`

- `withoutPlugins()` действует на плагины слоя `configure` (`configurePanels()`), как сказано в правиле пункта и в 05 §4.
  Плагин, подключённый самой панелью, им не снимается — иначе панель не могла бы заменить общий плагин своим с другими
  настройками (`withoutPlugins(['x'])->plugins([X::make(...)])`).
- `withoutPlugins()` из `register()` плагина → `DefinitionException`: к этому моменту часть плагинов уже зарегистрирована,
  молчаливое игнорирование было бы скрытой ошибкой. Досье этот случай не описывает — решение исполнителя, для Review P2.
- Повтор id строгий: тот же объект или класс, подключённый дважды, — тоже `PluginConflictException` (enum и роли при
  повторе схлопываются, плагины — нет: у двух подключений могут быть разные настройки).
- `requires()` с не-строкой → `DefinitionException` (тип из PHPDoc — обещание плагина).
- `AzGuardConfig::buildId(array $panelProviders)` принимает классы провайдеров аргументом: зона `Configuration` не зависит
  от `Panels` (arch-правило), а зарегистрированные провайдеры знает только реестр. Пустая строка в `catalog.build_id`
  означает «не задан». Fallback не следит за кодом вне файлов провайдеров — это записано в docblock и в комментарии конфига;
  проверку «production без явного id» вводит P6.4 (D8 п.5).
- Fluent-сеттер id плагина не вводился (D10 п.5: вопрос владельца открыт, P2.4 строит только геттер `Plugin::id()`).
- `tests/Arch/ZonesArchTest.php` не менялся: правила `Plugins ↛ Internal` и `Plugins ↛ Storage` уже были, с появлением
  классов зоны они стали проверяющими (доказательство ниже).
- Изменены три файла вне `Files` пункта:
  - `packages/core/src/Panels/PanelRegistry.php` — фаза `register` плагинов стоит между записью рецепта и его
    запечатыванием, а `boot` — после заморозки; обе точки находятся в реестре, без него компилятор плагины не получает;
  - `tests/Unit/Panels/PanelSettingsTest.php` — тест P2.3 запрещал любой публичный метод builder на `without…`;
    `withoutPlugins` (05 §4, D8 п.2) добавлен как названное исключение: он снимает плагин, а не гарантию;
  - `tests/Unit/Configuration/AzGuardConfigTest.php` — тесты `buildId()` и секции `catalog` лежат рядом с остальными
    тестами конфига.
  `AzGuardServiceProvider` не менялся: build id и контейнер реестр берёт из своего `Application`.

### Доказательство RED arch-правил (scratch-копия, не рабочее дерево)

Копия репозитория в каталоге задания (`rsync` без `.git`, `plans`, `audits`, `legacy`, `docs`) + probe-классы
`Internal\InternalProbe`, `Storage\StorageProbe` и `Plugins\PluginsProbe`, использующий оба:

| # | Упавшее правило | Сообщение |
|:--|:--|:--|
| 1 | `plugins do not reach into internals` | «Expecting 'AzGuard\Plugins' not to use 'AzGuard\Internal'» |
| 2 | `sources other than the database source and plugins stay off storage (AzGuard\Plugins)` | «Expecting 'AzGuard\Plugins' not to use 'AzGuard\Storage'» |

`vendor/bin/pest tests/Arch/ZonesArchTest.php` в копии: 44 теста, 2 failed (ровно эти два); в рабочем дереве — GREEN.

### Diff манифеста ядра

`+` `Contracts\Plugins\{Plugin,DependsOnPlugins}` (`@spi`), `Plugins\BasePlugin` (`@spi`, один метод `boot`),
`Plugins\PluginContext` (`@api`, 4 геттера и конструктор), `PluginDependencyMissingException`; `~` `PanelBuilder`:
`+plugins`, `+withoutPlugins` (28 записей); `~` `Panel`: `+pluginIds`, конструктор принимает `pluginIds`. Внутренние
`PanelRecipe`, `PanelCompiler`, `PanelFingerprint`, `AzGuardConfig` в манифест не попали.

### Validation

| # | Carrier | Result |
|:--|:--|:--|
| 1 | `vendor/bin/pest tests/Unit/Plugins tests/Feature/Plugins tests/Unit/Panels tests/Feature/Panels` | GREEN: 602 passed |
| 2 | `vendor/bin/pest tests/Arch` | GREEN: 55 passed |
| 3 | `php bin/api-manifest.php --check` после `composer api:manifest` | exit 0 |
| 4 | `composer test` | GREEN: 1156 passed |
| 5 | `vendor/bin/pint --test` | GREEN |
| 6 | `vendor/bin/phpstan analyse --memory-limit=1G` | GREEN: 0 errors |
| 7 | `php -d memory_limit=1G vendor/bin/pest --type-coverage --min=98` | GREEN: Total 100.0 % |
| 8 | `git diff --check` | clean |

Окружение: PHP 8.3.35, Laravel 13.34.0, Testbench 11.3.0, Pest 4.7.8, PHPStan 2.2.16; `vendor/` установлен в worktree
(`composer install` по `composer.lock` рабочей копии; lock не отслеживается).
