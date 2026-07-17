# Панели

Панель — это изолированное пространство имён для разрешений и ролей. Типичное приложение имеет три панели: `app`, `admin`, `api`.

## Provider панели

Панель описывается классом-провайдером, наследующим `AzGuard\PanelProvider`.
Метод `panel()` собирает панель через fluent-API.

```php
// app/Guards/App/AppGuardPanelProvider.php
namespace App\Guards\App;

use AzGuard\PanelProvider;
use AzGuard\Support\Panel;

class AppGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app') // префикс для всех прав: app.posts.view
            ->permissionEnums([
                Posts\Permissions\PostsPermission::class,
                Comments\Permissions\CommentsPermission::class,
                Reports\Permissions\ReportsPermission::class,
            ])
            ->roleClasses([
                Roles\EditorRole::class,
                Roles\ViewerRole::class,
                Roles\ModeratorRole::class,
            ]);
    }
}
```

## Регистрация в конфиге

В `panels` перечисляются FQCN провайдеров панелей:

```php
// config/az-guard.php
'panels' => [
    App\Guards\App\AppGuardPanelProvider::class,
    App\Guards\Admin\AdminGuardPanelProvider::class,
    App\Guards\Api\ApiGuardPanelProvider::class,
],
```

## Изоляция прав

Права между панелями не пересекаются. Enum-кейс привязывается к своей панели
автоматически; строковую форму используют как явный пример сборки полного ключа:

```php
// Назначаем роли — по классу (предпочтительно)
$user->assignRole(EditorRole::class);   // 'editor' по имени тоже работает

// Enum-кейс — привязан к своей панели автоматически
$user->hasPermission(UsersPermission::View);   // зависит от панели, где зарегистрирован enum

// app.users.view и admin.users.view — разные права (полный ключ с префиксом панели)
$user->hasPermission('app.users.view');   // false — нет роли в app
$user->hasPermission('admin.users.view'); // true  — есть роль в admin
```
