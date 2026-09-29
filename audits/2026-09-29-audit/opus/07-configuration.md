# 07 — Конфигурация

Решение: [D33](02-decisions.md#d33). Принципы — те же, что у Vaulter (Vaulter D26), чтобы хост настраивал оба пакета
одинаково:

1. **Файл на пакет**: `config/azguard.php` (ядро, включая бывший context) и `config/azguard-filament.php`.
2. **Схемо-влияющие ключи** (`database.*`, `ids.host_keys`) фиксируются в `azg_state.schema` при установке; doctor
   сравнивает. Их смена после миграции без upgrade — ошибка doctor.
3. **Один канонический ключ на понятие**; `ConfigNormalizer` переводит старые ключи `az-guard.*` с
   `E_USER_DEPRECATED` до 1.0; «старый и новый заданы по-разному» → `InvalidConfigurationException`.
4. **Типизированный доступ**: readonly `AzGuard\Configuration\AzGuardConfig` читает через `Config\Repository` без
   кэширования (тесты с `config()->set()` работают); `config('azguard…')` вне `Configuration\` запрещён arch-тестом.
5. **Валидация при boot**: ключи безопасности — исключение во всех окружениях; наблюдаемость — warning.
6. Замыкания в конфиге запрещены (`config:cache`); только скаляры, массивы, FQCN.
7. **Инварианты не настраиваются**: нельзя выключить валидацию каталога, атомарность «запись + ревизия», проверку
   сроков, грамматику идентичности (Codex, «configurable policies и стабильные invariants»).

## 1. `config/azguard.php`

```php
return [
    'database' => [
        'connection' => env('AZGUARD_DB_CONNECTION'),        // null = соединение по умолчанию (≙ vaulter.database.connection)
        'table_prefix' => 'azg_',                              // ≙ vaulter.database.table_prefix ('v_')
        'reads' => 'primary',                                   // primary | default — D24
    ],

    'ids' => [
        'host_keys' => 'string',                                // string (varchar 64) | bigint | uuid | ulid — ≙ vaulter.ids.host_keys
    ],

    'realms' => [
        'providers' => [
            // App\Authorization\AppRealm::class,
        ],
    ],

    'subjects' => [
        'resolver' => \AzGuard\Authorization\Subjects\ModelSubjectResolver::class,
        'types' => [],                                          // разрешённые morph-типы субъектов; пусто = любые
        'guard' => null,                                        // auth guard для «текущего субъекта» и директории
        'directory' => \AzGuard\Authorization\Subjects\GuardSubjectDirectory::class,
        'label_column' => 'name',
    ],

    'contexts' => [
        'resolvers' => [
            // 'workspace' => ['route' => 'workspace', 'type' => 'workspace'],   // встроенный route-резолвер
            // App\Authorization\CurrentWorkspaceResolver::class,
        ],
        'membership' => null,                                   // FQCN ContextMembership; обязателен, если realm требует членства
        'directory' => null,                                    // FQCN ContextDirectory для UI/CLI
    ],

    'authorization' => [
        'sources' => [
            'azguard/roles' => \AzGuard\Authorization\Sources\RolesSource::class,
            'azguard/grants' => \AzGuard\Authorization\Sources\GrantsSource::class,   // null — отключить гранты
        ],
        'constraints' => [
            // 'acme/license' => App\Authorization\LicenseConstraint::class,
        ],
        'superadmin' => [
            'policy' => \AzGuard\Authorization\Superadmin\AssignmentSuperadminPolicy::class,
            'platform_role' => true,                            // роль *:superadmin (D19)
            'bypass_constraints' => false,
        ],
        'gate' => [
            'enabled' => true,
            'mode' => 'authoritative',                          // authoritative | additive (deprecated к 1.0)
            'superadmin_scope' => 'owned',                      // owned | all
        ],
        'trace_decisions' => false,                             // диспатч AccessDecided на каждую проверку (диагностика)
    ],

    'administration' => [
        'delegation' => \AzGuard\Administration\DefaultDelegationPolicy::class,
        'prevent_escalation' => true,
        'direct_writes' => 'warn',                              // warn | throw — запись модели вне AccessManager (throw в local/testing по умолчанию)
    ],

    'cache' => [
        'store' => null,                                        // null = только request-кэш; имя store = межзапросный
        'ttl' => 3600,                                          // секунды; null запрещён на персистентном store
        'generation' => 1,                                      // смена при деплое открывает новое пространство ключей
        'state_refresh' => 'request',                           // request | check — D24
    ],

    'features' => [
        'grants' => true,                                       // прямые гранты (UI/CLI/AccessManager)
        'contexts' => true,                                     // назначения в контексте
        'audit' => false,                                       // azg_audit_log в транзакции записи
    ],

    'catalog' => [
        'providers' => [],                                      // глобальные CatalogProvider (realm-специфичные — в RealmBuilder)
        'permissions' => [],                                    // 'realm' => ['realm.x.y', …] — каталог без кода
        'cache_path' => null,                                   // null = bootstrap/cache/azguard.php
    ],

    'schedule' => [
        'enabled' => true,                                      // ≙ vaulter.schedule.enabled
        'prune_expired' => 'daily',                             // null — не регистрировать
    ],

    'models' => [
        'role' => \AzGuard\Persistence\Eloquent\Models\Role::class,
        'role_permission' => \AzGuard\Persistence\Eloquent\Models\RolePermission::class,
        'role_assignment' => \AzGuard\Persistence\Eloquent\Models\RoleAssignment::class,
        'grant' => \AzGuard\Persistence\Eloquent\Models\Grant::class,
        'audit_entry' => \AzGuard\Persistence\Eloquent\Models\AuditEntry::class,
    ],

    'scaffold' => [
        'namespace' => 'App\\Authorization',
        'path' => 'app/Authorization',
    ],

    'doctor' => [
        'checks' => [],                                         // дополнительные DoctorCheck
    ],
];
```

## 2. `config/azguard-filament.php`

```php
return [
    // Значения по умолчанию для AzGuardPlugin; fluent-вызовы плагина побеждают и НЕ пишутся обратно в config.
    'realm' => 'admin',                     // realm каталога Filament-ресурсов этой панели
    'manages' => null,                      // realm'ы, видимые в админ-UI; null = все
    'enforce' => true,
    'source' => 'database',                 // database | enum
    'abilities' => ['view_any', 'view', 'create', 'update', 'delete', 'restore', 'force_delete', 'replicate', 'reorder'],
    'key' => '{realm}.{resource}.{ability}',
    'resource_segment' => 'slug',           // slug (Resource::getSlug()) | model (morph alias) — не class_basename
    'pages' => ['ability' => 'view'],
    'widgets' => ['ability' => 'view'],
    'exclude' => ['resources' => [], 'pages' => [\Filament\Resources\Pages\CreateRecord::class], 'widgets' => []],
    'generation' => ['enum_namespace' => 'App\\Authorization\\Filament', 'enum_path' => 'app/Authorization/Filament'],
];
```

Удалены: `panel` (→ `realm`), `super_admin` (D19), `user_label_column` (→ `azguard.subjects.label_column`),
`generation.policy_*` и source `policy` (D26).

## 3. Карта устаревших ключей

Полная карта — [03 §8](03-glossary-and-renames.md#8-конфигурация). Нормализатор обрабатывает автоматически:

| Старый | Новый | Преобразование |
|---|---|---|
| `az-guard.column_names.morph_type` | `azguard.ids.host_keys` | `int→bigint`, `ulid→ulid`, `uuid→uuid` |
| `az-guard.table_names.*` | `azguard.database.table_prefix` | только для upgrade-миграции (находит старые таблицы); в рантайме — warning |
| `az-guard.cache.store = 'array'` | `azguard.cache.store = null` | |
| `az-guard.cache.expiration_time` | `azguard.cache.ttl` | |
| `az-guard.panels` | `azguard.realms.providers` | FQCN `PanelProvider` → ошибка с подсказкой (класс нужно переписать) |
| `az-guard.grant_sources` (allowlist) | `azguard.authorization.sources` | исключённый встроенный → `null` |
| `az-guard.features.direct_grants` | `azguard.features.grants` | |
| `az-guard.features.audit_log` | `azguard.authorization.trace_decisions` | |
| `az-guard.prune_expired_daily` | `azguard.schedule.prune_expired` | `true→'daily'`, `false→null` |
| `az-guard-context.resolvers` | `azguard.contexts.resolvers` | |
| `az-guard-context.merge_strategy` | — | ошибка с подсказкой: задать `ContextPolicy` на realm |
| прочие удалённые (N20, D05, D18) | — | `E_USER_DEPRECATED` «ключ удалён, не действует» |

## 4. Проверки при boot (исключение во всех окружениях)

| Проверка | Код |
|---|---|
| `ids.host_keys` ∉ {string,bigint,uuid,ulid} | `invalid_configuration.host_keys` |
| `cache.ttl = null` при персистентном драйвере store | `invalid_configuration.cache_ttl` (как сейчас C-04) |
| `database.reads` ∉ {primary, default}; `cache.state_refresh` ∉ {request, check}; `gate.mode`/`superadmin_scope` вне списка | `invalid_configuration.enum` |
| модель в `models.*` не наследует базовую / другое соединение | `invalid_configuration.model` |
| realm требует членства, а `contexts.membership = null` | `invalid_configuration.membership` |
| ключ источника/constraint не `vendor/name` или класс не реализует контракт | `invalid_configuration.extension` |
| старый и новый ключ заданы по-разному | `invalid_configuration.conflict` |

Warning (лог + doctor): `database.reads = default` при настроенных read-хостах; `gate.mode = additive`;
`administration.direct_writes = warn` в production при обнаруженных прямых записях; `cache.store` без
`LockProvider` не нужен больше (эпох нет) — проверка удаляется.
