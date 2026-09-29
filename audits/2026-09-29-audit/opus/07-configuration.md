# 07 — Конфигурация

Решения: [D33](02-decisions.md#d33), [D34](02-decisions.md#d34), [D45](02-decisions.md#d45), [D46](02-decisions.md#d46).

## 1. Где что настраивается — простыми словами

| Где | Что там | Пример |
|---|---|---|
| `config/azguard.php` | то, что **общее** для приложения: список панелей, хранилища, типы ключей хоста, расписание — и **значения по умолчанию** для всех панелей | `defaults.cache.ttl = 3600` |
| `PanelProvider` панели | всё **особенное** для панели | у `site` кэш 6 часов и контексты магазина |
| плагин | настройки, которые плагин приносит панели, если провайдер их не задал | плагин аудита включает наблюдение |
| `config/azguard-filament.php` | значения по умолчанию для Filament-плагина | какую guard panel показывать |

Эффективная настройка панели: **провайдер панели → плагины (в порядке подключения) → `defaults` из конфига**.
Команда `azguard:panels:list --settings` показывает итоговое значение и откуда оно пришло.

Принципы (общие инженерные правила экосистемы, D43):

1. Файл на пакет: `config/azguard.php`, `config/azguard-filament.php`.
2. Схемо-влияющие параметры (хранилища, `ids.host_keys`) фиксируются в таблице состояния хранилища; doctor
   сравнивает с конфигом.
3. Один канонический ключ на понятие; `ConfigNormalizer` переводит `az-guard.*` с `E_USER_DEPRECATED` до 1.0;
   «старый и новый заданы по-разному» → `InvalidConfigurationException`.
4. Типизированный доступ: readonly `AzGuardConfig` читает через `Config\Repository` без кэширования; `config('azguard…')`
   вне `Configuration\` запрещён arch-тестом.
5. Ошибки безопасности — исключение при boot во всех окружениях; наблюдаемость — warning.
6. Замыкания в конфиге запрещены (`config:cache`); замыкания допустимы в коде провайдера панели.
7. Инварианты не настраиваются ([D45](02-decisions.md#d45)).

## 2. `config/azguard.php`

```php
return [
    'panels' => [
        'providers' => [
            // App\Authorization\Panels\AdminPanelProvider::class,
            // App\Authorization\Panels\SitePanelProvider::class,
        ],
    ],

    'storages' => [
        'default' => [
            'connection' => env('AZGUARD_DB_CONNECTION'),   // null = соединение по умолчанию
            'table_prefix' => 'azg_',
            'host_keys' => null,                             // null = ids.host_keys
        ],
        // 'backoffice' => ['connection' => 'backoffice', 'table_prefix' => 'azg_', 'host_keys' => 'uuid'],
    ],

    'ids' => [
        'host_keys' => 'string',                             // string (varchar 64) | bigint | uuid | ulid
    ],

    'defaults' => [                                          // значения по умолчанию для всех панелей
        'plugins' => [                                       // встроенные плагины, подключаемые к каждой панели
            'azguard/roles', 'azguard/direct-grants', 'azguard/contexts', 'azguard/superadmin', 'azguard/access',
        ],
        'models' => [
            'role' => \AzGuard\Storage\Models\Role::class,
            'role_permission' => \AzGuard\Storage\Models\RolePermission::class,
            'role_assignment' => \AzGuard\Storage\Models\RoleAssignment::class,
            'direct_grant' => \AzGuard\Storage\Models\DirectGrant::class,
        ],
        'subjects' => [
            'guard' => null,                                 // null = guard по умолчанию
            'resolver' => \AzGuard\Authorization\Subjects\ModelSubjectResolver::class,
            'directory' => \AzGuard\Authorization\Subjects\GuardSubjectDirectory::class,
            'label_column' => 'name',
        ],
        'contexts' => [
            'resolvers' => [],
        ],
        'gate' => [
            'mode' => 'authoritative',                       // authoritative | additive (deprecated к 1.0)
            'superadmin_scope' => 'owned',                   // owned | all
        ],
        'superadmin' => [
            'bypass_restrictions' => false,
        ],
        'administration' => [
            'prevent_escalation' => true,
            'direct_writes' => 'warn',                       // warn | throw (throw в local/testing)
        ],
        'cache' => [
            'store' => null,                                 // null = только request-кэш
            'ttl' => 3600,
            'generation' => 1,
        ],
        'consistency' => [
            'reads' => 'primary',                            // primary | default
            'state_refresh' => 'request',                    // request | check
        ],
        'trace_decisions' => false,                          // AccessDecided на каждую проверку (диагностика)
    ],

    'gate' => [
        'enabled' => true,                                   // регистрировать Gate::before
    ],

    'schedule' => [
        'enabled' => true,
        'prune_expired' => 'daily',                          // null — не регистрировать
    ],

    'catalog' => [
        'cache_path' => null,                                // null = bootstrap/cache/azguard.php
    ],

    'scaffold' => [
        'namespace' => 'App\\Authorization',
        'path' => 'app/Authorization',
    ],
];
```

## 3. Настройки в провайдере панели

Полный список методов — [05 §3](05-php-api.md#3-панели). Типичный пример:

```php
public function panel(PanelBuilder $panel): PanelBuilder
{
    return $panel
        ->id('site')
        ->subjects(guard: 'web', models: [Customer::class])
        ->permissions(ShopPermission::class)
        ->contexts(ContextPolicy::inherit('store')->requireMembership(StoreMembership::class))
        ->storage('default')
        ->cache(ttl: 21600)
        ->consistency(refresh: StateRefresh::Request)
        ->gate(GateMode::Authoritative)
        ->withoutPlugin('azguard/direct-grants');   // на сайте прямые права не выдаём
}
```

## 4. `config/azguard-filament.php`

```php
return [
    // Значения по умолчанию для AzGuardPlugin; fluent-вызовы плагина побеждают и не пишутся обратно в config.
    'guard_panel' => 'admin',
    'manages' => null,                       // панели AzGuard, видимые в админке; null = все
    'enforce' => true,
    'source' => 'database',                  // database | enum
    'abilities' => ['view_any', 'view', 'create', 'update', 'delete', 'restore', 'force_delete', 'replicate', 'reorder'],
    'key' => '{panel}.{resource}.{ability}',
    'resource_segment' => 'slug',            // slug (Resource::getSlug()) | model (morph alias)
    'pages' => ['ability' => 'view'],
    'widgets' => ['ability' => 'view'],
    'exclude' => ['resources' => [], 'pages' => [\Filament\Resources\Pages\CreateRecord::class], 'widgets' => []],
    'generation' => ['enum_namespace' => 'App\\Authorization\\Filament', 'enum_path' => 'app/Authorization/Filament'],
];
```

## 5. Карта устаревших ключей

Полная карта — [03 §8](03-glossary-and-renames.md#8-конфигурация). Нормализатор:

| Старый | Новый | Преобразование |
|---|---|---|
| `az-guard.panels` | `azguard.panels.providers` | FQCN провайдеров переносятся; провайдер нужно переписать на `panel(PanelBuilder)` — ошибка с подсказкой |
| `az-guard.column_names.morph_type` | `azguard.ids.host_keys` | `int→bigint`, `ulid`, `uuid` |
| `az-guard.table_names.*` | `azguard.storages.default.table_prefix` | используется upgrade-миграцией для поиска старых таблиц |
| `az-guard.models.*` | `azguard.defaults.models.*` | `scope` → `role_assignment` (с предупреждением: модель переписать) |
| `az-guard.cache.store = 'array'` / `expiration_time` / `generation` | `azguard.defaults.cache.store = null` / `.ttl` / `.generation` | |
| `az-guard.grant_sources` | `azguard.defaults.plugins` | отсутствие `DirectGrantSource` → убрать `azguard/direct-grants` |
| `az-guard.features.direct_grants = false` | то же | |
| `az-guard.features.audit_log` | `azguard.defaults.trace_decisions` | |
| `az-guard.prune_expired_daily` | `azguard.schedule.prune_expired` | `true→'daily'`, `false→null` |
| `az-guard-context.resolvers` | `azguard.defaults.contexts.resolvers` | |
| `az-guard-context.merge_strategy` | — | ошибка с подсказкой: `ContextPolicy` на панели |
| `az-guard.default_panel`, `strict_panels`, `require_permission_attributes`, `scope.*`, `middleware.*`, `manager`, `resolver`, `matcher`, `abilities_resolver`, `role_permission_validator`, `fail_on_source_exception`, `features.teams`, `teams.*`, `features.wildcard_permission`, `features.validate_role_permissions` | — | `E_USER_DEPRECATED` «ключ удалён, не действует» |

## 6. Проверки при boot (исключение во всех окружениях)

| Проверка | Код |
|---|---|
| `host_keys` вне списка (глобально или у хранилища) | `invalid_configuration.host_keys` |
| `cache.ttl = null` при персистентном store (на любой панели) | `invalid_configuration.cache_ttl` |
| enum-настройки вне списка (`reads`, `state_refresh`, `gate.mode`, `superadmin_scope`, `direct_writes`) | `invalid_configuration.enum` |
| модель панели не наследует базовую / не совпадает с хранилищем | `storage_mismatch` |
| панель ссылается на неизвестное хранилище | `invalid_configuration.storage` |
| панель требует членства, а `ContextMembership` не задан | `invalid_configuration.membership` |
| ключ плагина/источника/ограничения не `vendor/name` или класс не реализует контракт | `invalid_configuration.extension` |
| конфликт настроек между плагинами панели | `plugin_conflict` |
| отсутствует зависимость плагина | `plugin_dependency_missing` |
| `configurePanel()` для незарегистрированной панели | `unknown_panel` |
| старый и новый ключ заданы по-разному | `invalid_configuration.conflict` |

Warning (лог + doctor): `reads = default` при read-хостах; `gate.mode = additive`; обнаруженные прямые записи в
production; панель `inherit` с контекстами без членства.
