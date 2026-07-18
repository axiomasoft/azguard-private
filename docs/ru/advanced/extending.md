# Расширение

AzGuard построен на контрактах и интерфейсах, что упрощает замену и расширение его компонентов.

## Кастомный GrantSource

Источник грантов — это любой класс, который производит `PermissionSet` для пользователя. AzGuard поставляется с несколькими встроенными: `ClassRoleGrantSource` и `DatabaseRoleGrantSource` (читают из ролей) и `DirectGrantSource` (читает из прямых грантов). Вы можете добавить свой:

```php
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;

class SubscriptionGrantSource implements GrantSource
{
    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
    {
        if ($user->subscription?->isPremium()) {
            return PermissionSet::fromKeys([
                'app.reports.export',
                'app.analytics.view',
            ]);
        }

        return PermissionSet::empty();
    }

    public function priority(): int
    {
        // Источники объединяются по приоритету — выше = разрешается первым
        return 50;
    }
}
```

Зарегистрируйте его в методе `register()` провайдера:

```php
use AzGuard\Facades\AzGuard;

public function register(): void
{
    AzGuard::registerGrantSource(SubscriptionGrantSource::class);
}
```

## Кастомный построитель каталога прав

Построитель каталога отвечает за сканирование и возврат всех определений прав для панели. Вы можете получать права из базы данных, конфига или удалённого API:

```php
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use AzGuard\Registry\Definitions\SimplePermissionDefinition;

class DatabaseCatalogBuilder implements PermissionCatalogBuilder
{
    public function build(string $panelId): array
    {
        // Получите права из вашего источника данных (БД, конфиг, удалённый API)
        $permissions = $this->fetchPermissions($panelId);

        return array_map(
            fn ($permission) => new SimplePermissionDefinition(
                key: $permission['key'],                             // например: 'app.documents.view'
                panelId: $panelId,
                group: $permission['group'] ?? null,                 // например: 'Documents'
                dynamic: str_contains($permission['key'], '{'),      // например: 'app.team.{id}.edit'
            ),
            $permissions
        );
    }

    public function supports(string $panelId): bool
    {
        // Вернуть true, если этот построитель обслуживает панель
        return true;
    }

    private function fetchPermissions(string $panelId): array
    {
        // Пример: получение из базы данных
        // return DB::table('permissions')->where('panel_id', $panelId)->get()->toArray();
        
        // Или из конфига
        // return config('my-permissions.'.$panelId, []);
        
        return [];
    }
}
```

Зарегистрируйте его в методе `boot()` провайдера:

```php
use AzGuard\Facades\AzGuard;

public function boot(): void
{
    AzGuard::registerCatalogBuilder(DatabaseCatalogBuilder::class);
}
```

## Замена базовых сервисов

AzGuard связывает пять швов «единственная активная стратегия» через
`config/az-guard.php`. Каждый — обычный биндинг контейнера, резолвящийся через
интерфейс: фасад и любой вызов проверки автоматически доходят до вашей замены,
никакого другого монтажа не требуется.

| Ключ конфига | Интерфейс | По умолчанию | Заменяет |
|:--|:--|:--|:--|
| `manager` | `AzGuardManagerInterface` | `AzGuardManager` | Панели, Grants API, `isSuperAdmin()`, `abilitiesFor()` |
| `resolver` | `PermissionResolverInterface` | `EffectivePermissionResolver` | Как источники грантов объединяются в `PermissionSet` |
| `matcher` | `PermissionMatcher` | `HierarchicalPermissionMatcher` | Грамматика сопоставления wildcard |
| `abilities_resolver` | `AbilitiesResolver` | `DefaultAbilitiesResolver` | Проекция ability для фронтенда (`AzGuard::abilitiesFor()`) |
| `role_permission_validator` | `RolePermissionValidator` | `CatalogRolePermissionValidator` | Опциональная защита `saving()` для ключей прав роли |

### `manager`

Декорируйте или замените `AzGuardManager`, чтобы добавить сквозное поведение
(аудит-лог, метрики) вокруг каждого вызова панели/гранта/супер-админа:

```php
use AzGuard\AzGuardManager;

class AuditingAzGuardManager extends AzGuardManager
{
    public function isSuperAdmin(\Illuminate\Contracts\Auth\Authenticatable $user, string|\BackedEnum|null $panelId = null): bool
    {
        $result = parent::isSuperAdmin($user, $panelId);

        if ($result) {
            report_super_admin_check($user, $panelId);
        }

        return $result;
    }
}
```

```php
// config/az-guard.php
'manager' => \App\Guards\AuditingAzGuardManager::class,
```

### `resolver`

Замените всю стратегию объединения/слияния — например, чтобы короткоциркуитить
по другому сигналу, нежели глобальный wildcard, или добавить телеметрию вокруг
резолюции. Должен реализовывать
`PermissionResolverInterface::forUser()`/`forgetForUser()`/`forgetRequestCache()`;
эталонная реализация (объединение GrantSources → опциональный PermissionLayer →
фильтр каталога → per-request кеш) — `EffectivePermissionResolver`.

```php
'resolver' => \App\Guards\LoggingPermissionResolver::class,
```

### `matcher`

Замените грамматику wildcard. AzGuard поставляет две: дефолтную
`HierarchicalPermissionMatcher` (сегмент-осознанная: `*` соответствует ровно
одному сегменту, `**` — рекурсивно) и legacy `WildcardPermissionMatcher`
(`*` пересекает точки, т.е. `app.*` покрывает `app.documents.view`). Legacy-грамматика
объявлена устаревшей и доступна ещё один цикл через feature-флаг, который
переопределяет этот ключ:

```php
// config/az-guard.php
'features' => [
    'wildcard_permission' => true,  // УСТАРЕЛО: вернуть legacy-грамматику 0.2
],
```

Собственная грамматика подключается через ключ `matcher`:

```php
'matcher' => \App\Guards\MyPermissionMatcher::class,
```

### `abilities_resolver`

Настройте, как строится проекция `AzGuard::abilitiesFor($user, $panelId, $keys)` —
например, добавьте кеширование или другое правило резолюции коротких ключей.
Должен реализовывать
`AbilitiesResolver::forUser(Authenticatable $user, string $panelId, array $keys): array<string, bool>`.

```php
'abilities_resolver' => \App\Guards\CachedAbilitiesResolver::class,
```

### `role_permission_validator`

Опциональная (`features.validate_role_permissions`) защита `saving()`, которая
отклоняет невалидный ключ `RolePermission` до того, как он молча выдаст доступ.
Замените её, чтобы обеспечить более строгую грамматику, чем «должен существовать
в каталоге» — например, отклонять wildcard-ключи независимо от флага
`wildcard_permission`:

```php
use AzGuard\Contracts\RolePermissionValidator;
use AzGuard\Registry\Exceptions\InvalidPermissionKeyException;

class NoWildcardRolePermissionValidator implements RolePermissionValidator
{
    public function validate(string $permissionKey, string $panelId): void
    {
        if (str_contains($permissionKey, '*')) {
            throw InvalidPermissionKeyException::forKey($permissionKey, $panelId);
        }
    }
}
```

```php
'role_permission_validator' => \App\Guards\NoWildcardRolePermissionValidator::class,
```

## Кастомная стратегия слияния (Context)

```php
use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Registry\Values\PermissionSet;

class CustomMergeStrategy implements MergeStrategy
{
    public function merge(PermissionSet $global, ?PermissionSet $context): PermissionSet
    {
        // Кастомная логика объединения глобальных и контекстных прав
        return $context ?? $global;
    }
}
```

Подключается через `config/az-guard-context.php`:

```php
'merge_strategy' => App\Guards\CustomMergeStrategy::class,
```

## Расширение трейта

```php
use AzGuard\Concerns\HasAzGuard;

trait HasCustomAzGuard
{
    use HasAzGuard;

    public function hasEveryPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }
}
```
