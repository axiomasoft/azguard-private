# Multi-Tenant роли

В SaaS-приложении пользователь может иметь разные роли в разных тенантах.
Используйте scoped-роли (привязка роли к сущности тенанта) и контекстные проверки.

## Scoped-роли по сущности

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Concerns\HasScopedRoles;

class User extends Authenticatable
{
    use HasAzGuard, HasScopedRoles;
}
```

```php
// Назначить роль в рамках конкретного тенанта
$user->assignScopedRole('editor', $tenant);

// Проверить право в этом тенанте
$user->hasScopedPermission(PostsPermission::Edit, $tenant);       // true

// В другом тенанте — нет
$user->hasScopedPermission(PostsPermission::Edit, $otherTenant);  // false
```

## Контекстная проверка по типу и ID

Если установлен пакет `axioma-studio/azguard-context`, можно проверять право
в контексте без отдельной сущности:

```php
$user->hasPermissionIn('tenant', $tenant->id, PostsPermission::Edit);        // панель по умолчанию
$user->hasPermissionIn('tenant', $tenant->id, 'app.posts.edit', 'app');      // явная панель
```

## Resolver контекста тенанта

```php
// app/Guards/TenantContextResolver.php
use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\Contracts\ResolvesContext;
use Illuminate\Http\Request;

class TenantContextResolver implements ResolvesContext
{
    public function resolve(Request $request): ?AuthorizationContext
    {
        $tenantId = $request->header('X-Tenant-ID')
            ?? auth()->user()?->tenant_id;

        return $tenantId
            ? new AuthorizationContext('app', 'tenant', $tenantId)
            : null;
    }

    public function panelId(): string
    {
        return 'app';
    }
}
```

Регистрируется в `config/az-guard-context.php`:

```php
'resolvers' => [
    App\Guards\TenantContextResolver::class,
],
```
