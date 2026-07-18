# Быстрый старт

За 5 минут до работающего RBAC.

## 1. Установка

```bash
composer require axioma-studio/azguard-core
php artisan vendor:publish --tag=az-guard-config
php artisan migrate
```

## 2. Добавьте трейт в User

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardUser;

class User extends Authenticatable implements AzGuardUser
{
    use HasAzGuard;
}
```

## 3. Создайте enum прав

```php
// app/Guards/App/Posts/Permissions/PostsPermission.php
namespace App\Guards\App\Posts\Permissions;

enum PostsPermission: string
{
    case View   = 'posts.view';
    case Create = 'posts.create';
    case Edit   = 'posts.edit';
    case Delete = 'posts.delete';
}
```

## 4. Создайте роль

```php
// app/Guards/App/Roles/EditorRole.php
namespace App\Guards\App\Roles;

use AzGuard\Roles\BaseRole;

class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [
            'app.posts.view',
            'app.posts.create',
            'app.posts.edit',
        ];
    }
}
```

## 5. Назначьте роль и проверьте

```php
// Назначение (по имени роли — из getName(): класс EditorRole → 'editor')
$user->assignRole('editor');

// Проверка
$user->hasPermission(PostsPermission::View);  // true
Gate::allows('app.posts.view');               // true

// Blade
@can('app.posts.edit')
    <a href="{{ route('posts.edit', $post) }}">Редактировать</a>
@endcan
```

::: tip
Полный список конфигурации — в разделе [Установка](/ru/introduction/installation).
:::
