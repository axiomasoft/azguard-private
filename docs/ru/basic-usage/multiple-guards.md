# Несколько Guards

AzGuard поддерживает несколько Laravel-гардов одновременно, каждый со своими панелями AzGuard.

## Как это работает

«Guard» здесь — концепция аутентификации Laravel: она решает, *кто* залогинен. Единица
изоляции AzGuard — **panel** (панель): она решает, *какое пространство разрешений*
(`app.*`, `admin.*`) применяется. В коде эти два понятия не связаны: панель — это просто
пространство разрешений, а какой auth guard стоит перед конкретным участком приложения —
решение вашей маршрутизации/middleware.

## Конфигурация

```php
// config/az-guard.php
'panels' => [
    App\Guards\App\AppGuardPanelProvider::class,
    App\Guards\Admin\AdminGuardPanelProvider::class,
    App\Guards\Api\ApiGuardPanelProvider::class,
],
```

Каждый provider панели объявляет свои роли и разрешения через `permissionEnums()` и `roleClasses()`.

## Проверка в рамках панели

```php
// Текущая панель запроса (устанавливается middleware azguard.panel)
$user->hasPermission(AdminPermission::ManageUsers);

// Явное указание панели вторым аргументом
$user->hasPermission(AdminPermission::ManageUsers, 'admin');
```

## Middleware для разных guard

```php
// routes/admin.php
Route::middleware(['auth:admin', 'azguard.panel:admin'])
    ->group(function () {
        Route::get('/users', [UserController::class, 'index']);
    });

// routes/api.php
Route::middleware(['auth:api', 'azguard.panel:api'])
    ->group(function () {
        Route::get('/me/permissions', [ProfileController::class, 'permissions']);
    });
```

## Filament с несколькими панелями

```php
// app/Providers/Filament/AdminPanelProvider.php
->authGuard('admin')
->plugin(AzGuardPlugin::make()->forPanel('admin'))
```

→ [Панели](/ru/advanced/panels) · [HTTP и Middleware](/ru/basic-usage/http-access)
