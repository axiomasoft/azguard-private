# Контекстные права (azguard/context)

Пакет `azguard/context` — opt-in расширение для multi-workspace / multi-site
сценариев. Пользователь может иметь **разные права в разных контекстах**
(workspace, project, organisation и т.д.) на одной и той же панели.

## Context или scope?

Context и [scope-роли на сущностях](/ru/advanced/entity-scopes) отвечают на похожий вопрос —
«может ли пользователь действовать здесь?» — но это два разных механизма, и намеренно:

| | Context (эта страница) | [Entity scope](/ru/advanced/entity-scopes) |
|---|---|---|
| Время жизни | Runtime — резолвится на запрос, живёт его длительность | Persisted — хранится как роль на модели |
| На какой вопрос отвечает | «В каком workspace/tenant я сейчас?» | «Есть ли у пользователя роль ИМЕННО на этой записи?» |
| Пакет | `azguard/context` (opt-in) | `azguard/core` (встроен) |
| API | `hasPermissionIn($type, $id, $perm)` | `hasScopedPermission($perm, $entity)` |

Выбирайте **context** для request-scoped переключателя «текущий workspace/tenant»,
который меняет применяемые права на весь остаток запроса. Выбирайте **entity scope**
для персистентной привязки «этот пользователь — editor именно на Project A», которая
переживает запросы. Они не сливаются и не заменяют друг друга — используйте оба
вместе, если в приложении есть и переключение tenant'ов, и роль на конкретной записи.

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
| **AuthorizationContextManager** | Singleton: хранит активный per-panel контекст на время запроса |
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
    App\Guards\WorkspaceContextResolver::class,
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
    {{-- права из текущего контекста (если middleware применён) --}}
@endazcan
```

## Выдача контекстных прав

Гранты хранятся в таблице `az_guard_context_roles`. Пишите их через тот же
fluent-корень, который уже используется для панельных direct grants —
`AzGuard::forUser()` — расширенный в контекст через `->inContext()`:

```php
use AzGuard\Facades\AzGuard;

AzGuard::forUser($user)
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->grant('app.posts.edit');

// С истечением срока — TTL работает точно так же, как у панельного direct grant
AzGuard::forUser($user)
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->until(now()->addDays(30))   // или ->ttl(3600)
    ->grant('app.posts.edit');

// Отозвать конкретное право
AzGuard::forUser($user)
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->revoke('app.posts.edit');

// Отозвать все права пользователя в этом контексте+панели
AzGuard::forUser($user)
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->revokeAll();

// Список активных (неистёкших) грантов в этом контексте+панели
AzGuard::forUser($user)
    ->on('app')
    ->inContext('workspace', $workspaceId)
    ->grants();
```

Builder иммутабелен: каждый сеттер scope-а (`on()` / `inContext()` /
`until()` / `ttl()`) возвращает **новый** инстанс, поэтому базовый builder
можно переиспользовать для нескольких записей. Повторная выдача уже
существующего права лишь обновляет его `expires_at` (идемпотентно).
Истёкший контекстный грант ничего не даёт и исключается из `grants()`.

::: warning Никаких wildcard в контексте
Wildcard-ключи (`*` или любой ключ, содержащий `*`) отклоняются на записи —
контекстный грант по дизайну ограничен областью и никогда не должен
обеспечивать superadmin/широкий доступ. Для выдачи широких прав на всю
панель используйте `AzGuard::forUser($user)->on('app')->grant(...)`.
:::

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

## Стратегии объединения

Настраивается в `config/az-guard-context.php`:

```php
'merge_strategy' => \AzGuard\Context\Strategies\GlobalPlusContextStrategy::class,
```

| Класс | Поведение |
|---|---|
| `GlobalPlusContextStrategy` | global ∪ context **(по умолчанию)** |
| `ContextOnlyStrategy` | только context, global игнорируется |
| `DenyWithoutContextStrategy` | пустой набор без контекста; с контекстом — global ∪ context |

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

Все источники объединяются в `EffectivePermissionResolver` — контекстные
права не «перебивают» class role, а расширяют набор.

## Обратная совместимость

- Пакет **opt-in**: если он не установлен, `HasAzGuard` работает точно так же, как раньше.
- `hasPermissionIn()` возвращает `false`, если пакет не установлен.
- `hasPermission(..., $context)` откатывается к глобальной проверке, если пакет не установлен.
