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

`make:guard-domain` добавляет enum нового домена в сгенерированный provider.
Роли могут возвращать его cases из `permissions()`; в custom provider enum
нужно добавить в `permissionEnums([...])` вручную.

## Назначение / снятие

```php
// Предпочтительно — по классу (точный class_name)
$user->assignRole(EditorRole::class);

// По persisted name: `{panelId}:{getName()}`
$user->assignRole('app:editor');

// Назначить несколько (variadic)
$user->assignRole(EditorRole::class, ModeratorRole::class);

// Снять
$user->removeRole(EditorRole::class);

// Синхронизация: только эти роли (остальные снимаются)
$user->syncRoles([EditorRole::class]);
```

## Проверка

```php
$user->hasRole(EditorRole::class); // true / false
$user->hasRole('app:editor');

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
`sync-roles` идемпотентна. Панельные code roles пишутся как `{panelId}:{getName()}`;
встроенный `SuperAdminRole` остаётся `super-admin`. Повторный запуск не меняет
совпавшие строки. `--dry-run` показывает те же решения. Старые неквалифицированные
строки (`editor`) больше не резолвят code role — мигрируйте на класс или
`app:editor`.
:::

→ [Лучшие практики: роли vs разрешения](/ru/best-practices/best-practices)
