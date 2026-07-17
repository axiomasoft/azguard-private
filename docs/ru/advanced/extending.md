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
