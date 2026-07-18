# Несколько Guards

AzGuard поддерживает приложения, использующие более одного guard'а аутентификации — например, guard `web` для обычных пользователей и `api` для мобильных клиентов, или отдельный guard `admin` для панели бэк-офиса.

## Как это работает

«Guard» здесь — это концепция аутентификации Laravel: она решает, *кто* залогинен. Единица
изоляции AzGuard — **панель** (panel): она решает, *какое пространство разрешений*
(`app.*`, `admin.*`) применяется. Эти два понятия не связаны в коде: панель — это просто
пространство разрешений, а то, какой auth guard стоит перед конкретным участком вашего
приложения, целиком определяется вашей собственной маршрутизацией и middleware.

На практике обычно один auth guard сочетается с одной панелью на каждую область приложения:

```
web guard   → App panel   → app.documents.view, app.posts.edit …
admin guard → Admin panel → admin.users.ban, admin.roles.manage …
```

## Конфигурация

Зарегистрируйте по одному panel provider на каждую область приложения в `config/az-guard.php`. `id()` каждой панели становится префиксом её разрешений:

```php
'panels' => [
    \App\Guards\App\AppGuardPanelProvider::class,    // id('app')   → app.*
    \App\Guards\Admin\AdminGuardPanelProvider::class,  // id('admin') → admin.*
],
```

## Проверка разрешений для конкретной панели

```php
// По умолчанию: резолвится относительно текущей панели (её задаёт middleware azguard.panel)
$user->hasPermission(DocumentsPermission::View);

// Явное указание другой панели — передайте её id вторым аргументом
$user->hasPermission(AdminUsersPermission::Ban, 'admin');
```

## Middleware с guards

```php
// Проверка разрешения, привязанного к конкретному guard'у, прямо в роутах
Route::middleware(['auth:admin', 'can:admin.users.ban'])
    ->group(function () {
        Route::post('/admin/users/{user}/ban', BanUserController::class);
    });
```

## Blade-директивы с guards

```blade
@can('admin.users.ban')
    <button>Забанить пользователя</button>
@endcan
```

`@can` в Blade автоматически резолвится относительно текущей панели. Чтобы проверить другую панель, вычислите булево значение в контроллере через `$user->hasPermission($permission, $panelId)` и передайте его во view.

::: tip
Полный референс по конфигурации панелей — в разделе [Панели](/ru/advanced/panels).
:::
