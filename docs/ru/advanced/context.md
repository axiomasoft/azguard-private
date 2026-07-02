# Контекстные права (azguard/context)

Пакет `azguard/context` — opt-in расширение для multi-workspace / multi-site сценариев.
Каждый пользователь может иметь **разные права в разных контекстах** (workspace, project, organisation и т.д.)
на одной и той же панели.

## Установка

```bash
composer require axioma-studio/azguard-context
php artisan vendor:publish --tag=azguard-context-migrations
php artisan migrate
```

## Концепции

| Термин | Описание |
|---|---|
| **AuthorizationContext** | Value object: `panelId` + `contextType` + `contextId` |
| **AuthorizationContextManager** | Singleton: хранит активный контекст per-panel на время request |
| **ResolvesContext** | Интерфейс resolver-а — извлекает контекст из `Request` |
| **MergeStrategy** | Стратегия объединения глобальных и контекстных прав |
| **ContextualRoleGrantSource** | `GrantSource` с приоритетом 95, читает таблицу `az_guard_context_roles` |

## Быстрый старт

### 1. Создайте resolver

```php
use AzGuard\Context\Contracts\ResolvesContext;
use AzGuard\Context\AuthorizationContext;
use Illuminate\Http\Request;

final class WorkspaceContextResolver implements ResolvesContext
{
    public function resolve(Request $request): ?AuthorizationContext
    {
        $id = $request->route('workspace');

        return $id
            ? new AuthorizationContext('app', 'workspace', $id)
            : null;
    }

    public function panelId(): string
    {
        return 'app';
    }
}
```

### 2. Зарегистрируйте в конфиге

```php
// config/az-guard-context.php
'resolvers' => [
    App\AzGuard\WorkspaceContextResolver::class,
],
```

### 3. Примените middleware к маршруту

Алиас `azguard.context` регистрируется автоматически в
`AzGuardContextServiceProvider::boot()` — ручного подключения в
`bootstrap/app.php` не требуется.

```php
// routes/web.php
Route::middleware(['auth', 'azguard.context'])
    ->group(function () {
        Route::get('/workspaces/{workspace}/posts', PostController::class);
    });
```

С этого момента `$user->hasPermission('app.posts.edit')` автоматически
учитывает права пользователя в текущем workspace.

## Проверка прав

### Глобальная (без контекста)

```php
$user->hasPermission('app.posts.edit');
```

### Одноразовая контекстная проверка

Не меняет глобальный `AuthorizationContextManager`:

```php
use AzGuard\Context\AuthorizationContext;

// Через удобный alias
$user->hasPermissionIn('workspace', $workspaceId, 'app.posts.edit');

// Через основной метод с объектом PermissionContext
$user->hasPermission('app.posts.edit', 'app', new AuthorizationContext(
    panelId: 'app',
    contextType: 'workspace',
    contextId: $workspaceId,
));
```

### Тихая версия (для Blade / UI)

```php
use AzGuard\Context\AuthorizationContext;

$user->checkPermission('app.posts.edit', 'app', new AuthorizationContext(
    panelId: 'app',
    contextType: 'workspace',
    contextId: $workspaceId,
));
```

### Blade-директива

```blade
@azcan('app.posts.edit')
    {{-- права из текущего контекста (если middleware установлен) --}}
@endazcan
```

## Выдача контекстных прав

Права хранятся в таблице `az_guard_context_roles`. Пишите их через
`ContextGrantBuilder` (fluent write-API, аналог `AzGuard\Grants\GrantBuilder`
для панельных direct grants) либо через CLI:

```php
use AzGuard\Context\ContextGrantBuilder;

(new ContextGrantBuilder($user))
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->grant('app.posts.edit');

// Отозвать конкретное право
(new ContextGrantBuilder($user))
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->revoke('app.posts.edit');

// Отозвать все права пользователя в этом контексте+панели
(new ContextGrantBuilder($user))
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->revokeAll();
```

Wildcard (`*`) — полный доступ в контексте:

```php
(new ContextGrantBuilder($user))
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->grant('*');
```

### CLI

```bash
# выдать контекстное право
php artisan guard:context:grant 42 app.posts.edit app workspace 7

# отозвать конкретное право
php artisan guard:context:revoke 42 app.posts.edit app workspace 7

# отозвать все права пользователя в этом контексте+панели
# (аргумент permission обязателен, но игнорируется при --all)
php artisan guard:context:revoke 42 ignored app workspace 7 --all
```

## Стратегии объединения прав

Настраивается в `config/az-guard-context.php`:

```php
'merge_strategy' => \AzGuard\Context\Strategies\GlobalPlusContextStrategy::class,
```

| Класс | Поведение |
|---|---|
| `GlobalPlusContextStrategy` | global ∪ context **(дефолт)** |
| `ContextOnlyStrategy` | только context, global игнорируется |
| `DenyWithoutContextStrategy` | пустой set без контекста; с контекстом — global ∪ context |

Можно реализовать свою стратегию:

```php
use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Registry\Values\PermissionSet;

final class MyStrategy implements MergeStrategy
{
    public function merge(PermissionSet $global, ?PermissionSet $context): PermissionSet
    {
        // ваша логика
    }
}
```

## Приоритеты GrantSource

| Source | Приоритет |
|---|---|
| ClassRoleGrantSource | 100 |
| **ContextualRoleGrantSource** | **95** |
| DatabaseRoleGrantSource | 90 |
| DirectGrantSource | 80 |

Все источники объединяются в `EffectivePermissionResolver` — контекстные права
не «перебивают» class role, а дополняют набор.

## Обратная совместимость

- Пакет **opt-in**: если не установлен, `HasAzGuard` работает идентично предыдущей версии.
- `hasPermissionIn()` возвращает `false` если пакет не установлен.
- `hasPermission(..., $context)` делает fallback к глобальной проверке если пакет не установлен.
