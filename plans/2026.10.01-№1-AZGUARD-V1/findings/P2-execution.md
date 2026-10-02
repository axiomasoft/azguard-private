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
