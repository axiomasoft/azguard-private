# Каталог разрешений

Каталог разрешений — это реестр всех enum-классов разрешений вашего приложения. AzGuard строит его из enum'ов, зарегистрированных на панелях.

## Структура каталога

```
app/Guards/
├── App/                    ← панель 'app'
│   ├── AppGuardPanelProvider.php
│   ├── Roles/
│   │   ├── EditorRole.php
│   │   └── ViewerRole.php
│   ├── Posts/
│   │   └── Permissions/
│   │       └── PostsPermission.php
│   ├── Comments/
│   │   └── Permissions/
│   │       └── CommentsPermission.php
│   └── Reports/
│       └── Permissions/
│           └── ReportsPermission.php
├── Admin/                  ← панель 'admin'
│   ├── AdminGuardPanelProvider.php
│   ├── Roles/
│   │   └── AdminRole.php
│   └── Users/
│       └── Permissions/
│           └── UsersPermission.php
└── Api/                    ← панель 'api'
    ├── ApiGuardPanelProvider.php
    ├── Roles/
    │   └── ApiConsumerRole.php
    └── Access/
        └── Permissions/
            └── AccessPermission.php
```

## Регистрация на панели

```php
// app/Guards/App/AppGuardPanelProvider.php
namespace App\Guards\App;

use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\Panel;

class AppGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->permissionEnums([
                Posts\Permissions\PostsPermission::class,
                Comments\Permissions\CommentsPermission::class,
                Reports\Permissions\ReportsPermission::class,
            ])
            ->roleClasses([
                Roles\EditorRole::class,
                Roles\ViewerRole::class,
            ]);
    }
}
```

## Просмотр каталога

```bash
php artisan guard:catalog
php artisan guard:list-permissions
```
