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
конфига**. Это precedence заменяемых настроек/presentation. Context predicates имеют additive AND contract
D75: provider/роль не удаляет обязательный фильтр defaults/plugin. Identity conflict отклоняется. Команда `azguard:panels:list --settings` показывает итоговое значение и откуда оно пришло.

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

    'sources' => [                                           // параметры именованных источников: ->permissions(['ldap'])
        // 'ldap' => ['group_attribute' => 'memberOf', 'map' => ['CN=Support' => 'support']],
    ],

    'defaults' => [                                          // значения по умолчанию для всех панелей
        'prefixed' => true,                                  // префикс имён прав: true (id панели, как сейчас) | false; свой — на панели
        'models' => [                                        // модели DatabaseSource по умолчанию
            'role_grant' => \AzGuard\Storage\Models\RoleGrant::class,
            'permission_grant' => \AzGuard\Storage\Models\PermissionGrant::class,
            'permission' => \AzGuard\Storage\Models\Permission::class,        // динамические права
        ],
        'tenants' => ['resolvers' => []],                     // TenantPolicy по умолчанию none, required задаёт panel
        'contexts' => [
            'resolvers' => [],
        ],
        'gate' => [
            'mode' => 'authoritative',                       // owned actions always final; foreign native abilities untouched
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
        // direct writes запрещены во всех environments; режима warn для сохранения нет
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

    'discovery' => [                                         // FolderSource: имена корней по типам классов (D56/D72)
        'permissions' => 'Permissions',                      // Permissions/{Group}/*Permission.php
        'policies' => 'Policies',                            // Policies/{Group}/*Policy.php
        'roles' => 'Roles',                                  // Roles/*Role.php
        'contexts' => 'Contexts',                            // ContextDefinition; не произвольные business models
        'abilities' => 'Abilities',                          // Abilities/{Group}/*Abilities.php
        'queries' => 'Queries',                              // Queries/{Group}; adapters подключаются явно
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
        ->for([User::class], guard: 'web')
        ->middleware(['web', 'auth:web'])
        ->entry('panel.access')
        ->permissions([
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
    // enum и политики для режимов enum/policy генерируются в папку guard_panel: app/Guards/Admin/{Permissions,Policies}/{Group}/…
];
```

## 5. Проверки при запуске

Исключение во всех окружениях:

| Проверка | Код |
|---|---|
| `host_keys` вне списка (глобально или у хранилища) | `invalid_configuration.host_keys` |
| `cache.ttl = null` при постоянном store (на любой панели) | `invalid_configuration.cache_ttl` |
| значения-перечисления вне списка (`reads`, `state_refresh`, `gate.mode`) | `invalid_configuration.enum` |
| модель `DatabaseSource` не наследует базовую или не совпадает с хранилищем (в том числе по `#[Table]`/`#[Connection]`) | `storage_mismatch` |
| `DatabaseSource` ссылается на неизвестное хранилище | `invalid_configuration.storage` |
| имя источника не зарегистрировано (`->permissions(['ldap'])` без `#[AsSource]`/`extend()`) | `unknown_source` |
| на панели два источника-писателя | `writer_conflict` |
| два источника с одним `id()` на панели; права или роли разных источников сталкиваются | `duplicate_permission`, `duplicate_role` |
| две панели по умолчанию для одной модели | `default_panel_conflict` |
| панель требует членства, а `ContextMembership` не задан | `invalid_configuration.membership` |
| статичная роль из связи (`RelationSource::make(…, role: 'owner')`) не существует на панели | `unknown_role` |
| право привязано к двум политикам | `duplicate_policy_binding` |
| префикс панели повторяется или совпадает с первым сегментом локального имени | `prefix_conflict` |
| неоднозначная пара enum/policy одной группы без явной привязки; сигнатура метода политики не подходит | `invalid_policy_structure` |
| required tenant без membership/owner resolver; role context binding вне зарегистрированных definitions | `invalid_configuration.tenant_scope` |
| любой `gate.mode`, кроме authoritative | `invalid_configuration.enum` |
| PolicyOnly без binding; отсутствующий метод explicit PolicyBinding; map неизвестной Gate ability | `invalid_policy_structure` |
| один physical storage зарегистрирован под разными id; неканоничный identity HK | `storage_mismatch` |
| конфликт настроек между плагинами панели | `plugin_conflict` |
| в строгом режиме (`requireRouteChecks()`) у действия нет `azguard.can` (из `#[CheckPermission]`), Laravel `can`/`#[Authorize]` и `#[SkipPermissionCheck]` (проверяет doctor; при запросе — исключение в local/testing) | `missing_permission_check` |
| не хватает зависимости плагина | `plugin_dependency_missing` |
| `configurePanel()` для незарегистрированной панели | `unknown_panel` |

Предупреждения (лог + doctor): `reads = default` при read-хостах; попытки прямых записей моделей (запись отклоняется); `inherit` с контекстами без членства; поле из `decisionFields` лежит в `meta`; публичный метод политики
домена не совпал ни с одним кейсом; автопоиск без `azguard:catalog:cache` в production.


## 6. Сборка: scalar overrides и списки

Настройки tenant/resource integrity не переопределяются плагином. Sources/roles/contexts/hooks/plugins —
аддитивные списки с origin metadata и проверкой дублей; `configurePanel(id)` — дополнение провайдера.
Scalar settings используют указанный приоритет с tracked explicit/default values; одинаковые callbacks
не применяются повторно к каждой фазе. Plugin register добавляет definitions, boot не меняет registry.
Prefixes преобразуют все связанные ссылки (enum binding, Role.permissions, policies, schema), не только имена.

SourceManager parameters могут иметь per-panel overlay; creator получает эффективный config и execution scope,
а не общий mutable singleton user. Secret connection params не печатаются sources:list/settings/doctor.
`generation` вручную не заменяет deployment build id; policy source code тоже меняет fingerprint (D64).

Корни discovery расположены прямо в папке провайдера или discover root. `permissions` и `policies`
должны быть разными каталогами; совпадающие/пересекающиеся настроенные корни механизмов отклоняются.
Относительная группа между параллельными корнями сопоставляется внутри одного discovery root (D56/D72).
Discovery config и origin roots входят в catalog fingerprint; настройки не меняют model ownership.

Typed context filters/plugin parameters/D74–D83: [18](18-contexts-and-runtime-inputs.md).
Общие фильтры нескольких build contributors соединяются через AND; role-specific recipe добавляется внутри
role branch, не заменяет common query. Display defaults следуют precedence, ownership не настраивается.
BaseRole definitions/filters только в коде. Concrete Plugin::make(models:, projects:, ...) получает typed config;
никакого nested options parser; Build PluginContext передаётся register/boot. Current user/Role/Request/Builder
не являются допустимыми build inputs. Secret refs не публикуются, code/build id и recipes входят в fingerprint.
