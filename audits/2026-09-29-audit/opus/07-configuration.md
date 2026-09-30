# 07 — Конфигурация

Решения: [D33](02-decisions.md#d33), [D34](02-decisions.md#d34), [D45](02-decisions.md#d45), [D46](02-decisions.md#d46),
[D52](02-decisions.md#d52), [D58](02-decisions.md#d58).

## 1. Где что настраивается

| Где | Что там | Пример |
|---|---|---|
| `config/azguard.php` | то, что **общее** для приложения: список панелей, хранилища, тип ключей, параметры именованных источников, расписание — и **значения по умолчанию** для всех панелей | `defaults.cache.ttl = 3600`, `sources.ldap.*` |
| `PanelProvider` панели (в папке панели) | всё **особенное** для панели: субъекты, префикс, источники, контексты, хуки; права, политики и роли лежат рядом в папке | у `cabinet` нет `DatabaseSource`, у `admin` есть |
| источник | свои настройки источника: таблицы и модели у `DatabaseSource`, связь у `RelationSource` | `DatabaseSource::make()->storage('backoffice')` |
| плагин | значения, которые плагин приносит панели, если провайдер их не задал | плагин аудита включает журнал |
| `AzGuard::configurePanels()` | одна настройка для всех панелей в коде (можно с замыканием) | роль `RootRole` во всех панелях |
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
            // App\Guards\Cabinet\CabinetGuardPanelProvider::class,
            // App\Guards\Admin\AdminGuardPanelProvider::class,
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

    'sources' => [                                           // параметры именованных источников: ->sources(['ldap'])
        // 'ldap' => ['group_attribute' => 'memberOf', 'map' => ['CN=Support' => 'support']],
    ],

    'defaults' => [                                          // значения по умолчанию для всех панелей
        'prefixed' => true,                                  // префикс имён прав: true (id панели, как сейчас) | false; свой — на панели
        'models' => [                                        // модели DatabaseSource по умолчанию
            'role' => \AzGuard\Storage\Models\Role::class,
            'role_permission' => \AzGuard\Storage\Models\RolePermission::class,
            'role_grant' => \AzGuard\Storage\Models\RoleGrant::class,
            'permission_grant' => \AzGuard\Storage\Models\PermissionGrant::class,
            'permission' => \AzGuard\Storage\Models\Permission::class,        // динамические права
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

    'discovery' => [                                         // FolderSource: имена подпапок в папке панели (D56)
        'permissions' => 'Permissions',                      // {Domain}/Permissions/*Permission.php
        'policies' => 'Policies',                            // {Domain}/Policies/*Policy.php
        'roles' => 'Roles',                                  // Roles/*Role.php
        'abilities' => 'Abilities',                          // {Domain}/Abilities/*Abilities.php
        'shared' => 'Shared',                                // app/Guards/Shared: не панель; здесь ищутся #[AsSource]
    ],

    'scaffold' => [                                          // куда генераторы кладут папки панелей
        'namespace' => 'App\\Guards',
        'path' => 'app/Guards',
    ],
];
```

## 3. Настройки в провайдере панели

Полный список методов — [05 §4](05-php-api.md#4-описание-панели-panelprovider-и-panelbuilder). Пример:

```php
public function panel(PanelBuilder $panel): PanelBuilder
{
    return $panel
        ->id('seller')                                     // имена прав: seller.orders.cancel (префикс по умолчанию)
        ->subjects([User::class], guard: 'web')
        ->middleware(['web', 'auth:web'])
        ->entry('panel.access')
        ->sources([
            RelationSource::make(Store::class, via: 'staff', role: 'pivot.role'),
            DatabaseSource::make()->rolesOnly()->storage('default'),   // только роли, без выдач отдельных прав
        ])
        ->contexts(ContextPolicy::inherit(Store::class)->requireMembership(StoreStaff::viaRelation('staff')))
        ->cache(ttl: 21600);
}
```

## 4. `config/azguard-filament.php`

```php
return [
    // Значения по умолчанию для AzGuardPlugin; вызовы плагина в провайдере Filament-панели побеждают
    'guard_panel' => 'admin',
    'manages' => null,                       // панели AzGuard, чьими ролями управляет админка; null = все с DatabaseSource
    'enforce' => true,
    'source' => 'database',                  // права ресурсов: database (на лету) | enum (enum в папке панели) | policy (enum + политика)
    'abilities' => ['view_any', 'view', 'create', 'update', 'delete', 'restore', 'force_delete', 'replicate', 'reorder'],
    'key' => '{resource}.{ability}',         // локальное имя; панель — guard_panel
    'resource_segment' => 'slug',            // slug (Resource::getSlug()) | model (morph alias)
    'pages' => ['ability' => 'view'],
    'widgets' => ['ability' => 'view'],
    'exclude' => ['resources' => [], 'pages' => [], 'widgets' => []],
    // enum и политики для режимов enum/policy генерируются в папку guard_panel: app/Guards/Admin/{Resource}/…
];
```

## 5. Проверки при запуске

Исключение во всех окружениях:

| Проверка | Код |
|---|---|
| `host_keys` вне списка (глобально или у хранилища) | `invalid_configuration.host_keys` |
| `cache.ttl = null` при постоянном store (на любой панели) | `invalid_configuration.cache_ttl` |
| значения-перечисления вне списка (`reads`, `state_refresh`, `gate.mode`, `direct_writes`) | `invalid_configuration.enum` |
| модель `DatabaseSource` не наследует базовую или не совпадает с хранилищем (в том числе по `#[Table]`/`#[Connection]`) | `storage_mismatch` |
| `DatabaseSource` ссылается на неизвестное хранилище | `invalid_configuration.storage` |
| имя источника не зарегистрировано (`->sources(['ldap'])` без `#[AsSource]`/`extend()`) | `unknown_source` |
| на панели два источника-писателя | `writer_conflict` |
| два источника с одним `id()` на панели; права или роли разных источников сталкиваются | `duplicate_permission`, `duplicate_role` |
| две панели по умолчанию для одной модели | `default_panel_conflict` |
| панель требует членства, а `ContextMembership` не задан | `invalid_configuration.membership` |
| статичная роль из связи (`RelationSource::make(…, role: 'owner')`) не существует на панели | `unknown_role` |
| право привязано к двум политикам | `duplicate_policy_binding` |
| префикс панели повторяется или совпадает с первым сегментом локального имени | `prefix_conflict` |
| значения enum ресурса начинаются с разных сегментов; сигнатура метода политики не подходит | `invalid_policy_structure` |
| конфликт настроек между плагинами панели | `plugin_conflict` |
| в строгом режиме (`requireRouteChecks()`) у действия нет `azguard.can` (из `#[CheckPermission]`), Laravel `can`/`#[Authorize]` и `#[SkipPermissionCheck]` (проверяет doctor; при запросе — исключение в local/testing) | `missing_permission_check` |
| не хватает зависимости плагина | `plugin_dependency_missing` |
| `configurePanel()` для незарегистрированной панели | `unknown_panel` |

Предупреждения (лог + doctor): `reads = default` при read-хостах; `gate.mode = additive`; прямые записи моделей в
production; `inherit` с контекстами без членства; поле из `decisionFields` лежит в `meta`; публичный метод политики
домена не совпал ни с одним кейсом; кейс домена с политикой без метода и без `#[GrantsOnly]`; роль без `#[Role]` (ключ
из имени класса); автопоиск без `azguard:catalog:cache` в production.
