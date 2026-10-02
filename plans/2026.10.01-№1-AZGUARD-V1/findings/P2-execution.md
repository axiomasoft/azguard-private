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
