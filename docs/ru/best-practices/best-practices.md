# Роли vs Разрешения

## Когда использовать роли

Роль — это **набор разрешений**, объединённых бизнес-логикой. Назначайте роли, когда:

- Группа пользователей делает одно и то же (редакторы, модераторы, менеджеры)
- Набор прав меняется вместе (добавили фичу — добавили в роль)
- Нужно проверить принадлежность к группе (`hasRole('app:editor')`)

```php
// ✅ Хорошо — роль отражает бизнес-роль
$user->assignRole('app:editor');

// ❌ Плохо — 15 прямых грантов вместо одной роли
$user->grant(PostsPermission::View, 'app');
$user->grant(PostsPermission::Create, 'app');
// ...
```

## Когда использовать прямые гранты

Прямой грант — исключение из роли. Используйте, когда:

- Нужен **временный** доступ (с TTL)
- Один пользователь должен получить право, которое не входит в его роль
- Нужно перекрыть доступ без изменения роли

## Принцип минимальных прав

```php
use AzGuard\Roles\BaseRole;

// ✅ Хорошо — каждая роль содержит только нужные права
class ViewerRole extends BaseRole
{
    public function permissions(): array
    {
        return ['app.posts.view', 'app.comments.view'];
    }
}

// ❌ Плохо — роль «на всякий случай» с лишними правами
class ViewerRole extends BaseRole
{
    public function permissions(): array
    {
        return [
            'app.posts.view',
            'app.posts.edit',   // Зачем?
            'app.posts.delete', // Точно не нужно
        ];
    }
}
```

## Именование

- Роли: существительные, отражающие бизнес-роль — `EditorRole`, `ModeratorRole`, `BillingManagerRole`
- Разрешения: `{Ресурс}Permission` — `PostsPermission`, `InvoicesPermission`
- Кейсы: глагол или глагол+существительное — `View`, `Create`, `ExportToPdf`
