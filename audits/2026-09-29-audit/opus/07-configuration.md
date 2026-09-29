# 07 — Конфигурация

Решения: [D33](02-decisions.md#d33), [D34](02-decisions.md#d34), [D45](02-decisions.md#d45), [D46](02-decisions.md#d46).

## 1. Где что настраивается

| Где | Что там | Пример |
|---|---|---|
| `config/azguard.php` | то, что **общее** для приложения: список панелей, хранилища, тип ключей, расписание — и **значения по умолчанию** для всех панелей | `defaults.cache.ttl = 3600` |
| `PanelProvider` панели | всё **особенное** для панели: субъекты, механики, контексты, суперадмин, хуки | у `cabinet` нет БД, у `admin` есть |
| плагин | значения, которые плагин приносит панели, если провайдер их не задал | плагин аудита включает журнал |
| `AzGuard::configurePanels()` | одна настройка для всех панелей в коде (можно с замыканием) | общее правило суперадмина |
| `config/azguard-filament.php` | значения по умолчанию для Filament-плагина | какую панель AzGuard показывать |

Итоговая настройка панели: **провайдер панели → плагины (в порядке подключения) → `configurePanels()` → `defaults` из
конфига**. Команда `azguard:panels:list --settings` показывает итоговое значение и откуда оно пришло.

Принципы (общие инженерные правила экосистемы, D43):

1. Файл на пакет: `config/azguard.php`, `config/azguard-filament.php`.
2. Параметры, влияющие на схему БД (хранилища, `ids.host_keys`), фиксируются в таблице состояния хранилища; doctor
   сравнивает их с конфигом.
3. Один ключ на понятие. Старых ключей и нормализатора нет ([D01](02-decisions.md#d01)).
4. Типизированный доступ: readonly `AzGuardConfig`; `config('azguard…')` вне `Configuration\` запрещён arch-тестом.
5. Ошибки безопасности — исключение при загрузке во всех окружениях; наблюдаемость — предупреждение.
6. Замыканий в конфиге нет (`config:cache`); замыкания допустимы в коде провайдера панели.
7. Гарантии D45 не настраиваются.

## 2. `config/azguard.php`

```php
return [
    'panels' => [
        'providers' => [
            // App\Authorization\Panels\CabinetPanelProvider::class,
            // App\Authorization\Panels\AdminPanelProvider::class,
        ],
    ],

    'storages' => [
        'default' => [
            'connection' => env('AZGUARD_DB_CONNECTION'),   // null = подключение по умолчанию
            'table_prefix' => 'azg_',
            'host_keys' => null,                             // null = ids.host_keys
        ],
        // 'backoffice' => ['connection' => 'backoffice', 'table_prefix' => 'azg_', 'host_keys' => 'uuid'],
    ],

    'ids' => [
        'host_keys' => 'string',                             // string (varchar 64) | bigint | uuid | ulid
    ],

    'defaults' => [                                          // значения по умолчанию для всех панелей
        'database' => true,                                  // механика «роли и права в БД» включена, пока панель не скажет иначе
        'models' => [
            'role' => \AzGuard\Storage\Models\Role::class,
            'role_permission' => \AzGuard\Storage\Models\RolePermission::class,
            'role_assignment' => \AzGuard\Storage\Models\RoleAssignment::class,
            'direct_permission' => \AzGuard\Storage\Models\DirectPermission::class,
        ],
        'super_admin' => [
            'role' => 'superadmin',                          // null — правило по роли не действует
            'when' => null,                                  // класс SuperAdminRule, например App\Authorization\IsRoot
        ],
        'contexts' => [
            'resolvers' => [],
        ],
        'gate' => [
            'mode' => 'authoritative',                       // authoritative | additive
        ],
        'cache' => [
            'store' => null,                                 // null = только кэш в пределах запроса
            'ttl' => 3600,
            'generation' => 1,
        ],
        'consistency' => [
            'reads' => 'primary',                            // primary | default
            'state_refresh' => 'request',                    // request | check
        ],
        'direct_writes' => 'strict',                         // strict (исключение в local/testing, warning в production) | warn
        'trace_decisions' => false,                          // событие AccessDecided на каждую проверку (диагностика)
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

Полный список методов — [05 §4](05-php-api.md#4-описание-панели-panelprovider-и-panelbuilder). Пример:

```php
public function panel(PanelBuilder $panel): PanelBuilder
{
    return $panel
        ->id('seller')
        ->subjects(User::class, guard: 'web')
        ->middleware(['web', 'auth:web'])
        ->entry(SellerPermission::Access)
        ->permissions(SellerPermission::class)
        ->roles(SellerRole::class)
        ->relation(Store::class, via: 'staff', role: 'pivot.role')
        ->database(directPermissions: false)               // только роли, без прямых прав
        ->contexts(ContextPolicy::inherit(Store::class)->requireMembership(StoreStaff::viaRelation('staff')))
        ->cache(ttl: 21600)
        ->superAdmin(false);
}
```

## 4. `config/azguard-filament.php`

```php
return [
    // Значения по умолчанию для AzGuardPlugin; вызовы плагина в провайдере Filament-панели побеждают
    'guard_panel' => 'admin',
    'manages' => null,                       // панели AzGuard, чьими ролями управляет админка; null = все с механикой БД
    'enforce' => true,
    'abilities' => ['view_any', 'view', 'create', 'update', 'delete', 'restore', 'force_delete', 'replicate', 'reorder'],
    'key' => '{resource}.{ability}',         // локальное имя; панель — guard_panel
    'resource_segment' => 'slug',            // slug (Resource::getSlug()) | model (morph alias)
    'pages' => ['ability' => 'view'],
    'widgets' => ['ability' => 'view'],
    'exclude' => ['resources' => [], 'pages' => [], 'widgets' => []],
    'generation' => ['enum_namespace' => 'App\\Authorization\\Filament', 'enum_path' => 'app/Authorization/Filament'],
];
```

## 5. Проверки при запуске

Исключение во всех окружениях:

| Проверка | Код |
|---|---|
| `host_keys` вне списка (глобально или у хранилища) | `invalid_configuration.host_keys` |
| `cache.ttl = null` при постоянном store (на любой панели) | `invalid_configuration.cache_ttl` |
| значения-перечисления вне списка (`reads`, `state_refresh`, `gate.mode`, `direct_writes`) | `invalid_configuration.enum` |
| модель панели не наследует базовую или не совпадает с хранилищем | `storage_mismatch` |
| панель ссылается на неизвестное хранилище | `invalid_configuration.storage` |
| две панели по умолчанию для одной модели | `default_panel_conflict` |
| панель требует членства, а `ContextMembership` не задан | `invalid_configuration.membership` |
| фиксированная роль из связи (`->relation(role: 'owner')`) не существует на панели | `unknown_role` |
| право привязано к двум политикам | `duplicate_policy_binding` |
| конфликт настроек между плагинами панели | `plugin_conflict` |
| не хватает зависимости плагина | `plugin_dependency_missing` |
| `configurePanel()` для незарегистрированной панели | `unknown_panel` |

Предупреждения (лог + doctor): `reads = default` при read-хостах; `gate.mode = additive`; прямые записи моделей в
production; `inherit` с контекстами без членства; поле из `decisionFields` лежит в `meta`.
