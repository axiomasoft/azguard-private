# azguard/context

Opt-in пакет для multi-workspace / multi-site поддержки в AzGuard.

## Установка

```bash
composer require axioma-studio/azguard-context
php artisan vendor:publish --tag=azguard-context-migrations
php artisan migrate
```

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

    public function panelId(): string { return 'app'; }
}
```

### 2. Зарегистрируйте в конфиге

```php
// config/az-guard-context.php
'resolvers' => [
    App\AzGuard\WorkspaceContextResolver::class,
],
```

### 3. Подключите middleware

```php
// routes/web.php
Route::middleware('azguard.context')->group(function () {
    Route::get('/workspaces/{workspace}/posts', PostController::class);
});
```

С этого момента `hasPermission('app.posts.edit')` автоматически учитывает
права пользователя в текущем workspace.

## Стратегии

| Класс | Поведение |
|---|---|
| `GlobalPlusContextStrategy` | global ∪ context (дефолт) |
| `ContextOnlyStrategy` | только context, global игнорируется |
| `DenyWithoutContextStrategy` | без контекста — пусто |

См. `docs/guide/context.md` для полной документации.
