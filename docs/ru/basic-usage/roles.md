# Роли

В AzGuard роль — это PHP-класс, расширяющий `BaseRole` (реализует `RoleInterface`). Метод `permissions()` возвращает список полных ключей прав — база данных хранит только связку `user → role`.

## Определение роли

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
            'app.comments.view',
            'app.comments.moderate',
        ];
    }
}
```

## Назначение / снятие

```php
// Назначить одну роль (по имени: класс EditorRole → 'editor')
$user->assignRole('editor');

// Назначить несколько (variadic)
$user->assignRole('editor', 'moderator');

// Снять
$user->removeRole('editor');

// Синхронизация: только эти роли (остальные снимаются)
$user->syncRoles(['editor']);
```

## Проверка

```php
$user->hasRole('editor');        // true / false

$user->getRoleNames();           // Collection<string> — имена всех ролей
$user->roles;                    // Collection моделей Role (отношение)
```

## Наследование ролей

```php
class AdminRole extends BaseRole
{
    public function permissions(): array
    {
        return [
            ...(new EditorRole)->permissions(),  // все права Editor
            'app.users.manage',
            'app.settings.edit',
        ];
    }
}
```

## Регистрация в БД

```bash
# Синхронизировать PHP-роли с таблицей roles
php artisan guard:sync-roles
```

::: tip
`sync-roles` идемпотентна. Запускайте в миграциях или CI при деплое.
:::

→ [Лучшие практики: роли vs разрешения](/ru/best-practices/best-practices)
