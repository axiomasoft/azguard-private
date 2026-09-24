# Мягкое переопределение роли

Иногда нужно дать пользователю права сверх его роли без изменения самой роли — например, временно выдать право публикации редактору.

## Решение: комбинация роли + прямого гранта

```php
// Пользователь — редактор, но сегодня может публиковать
$user->assignRole('app:editor');
$user->grant(
    PostsPermission::Publish,
    'app',
    expiresAt: Carbon::now()->endOfDay()
);

// AzGuard проверяет и роли, и гранты
$user->hasPermission(PostsPermission::Publish); // true до конца дня
```

## Через Gate::before()

```php
Gate::before(function (User $user, string $ability) {
    // Проверяем специальный флаг модели
    if ($user->has_temporary_publish_access && $ability === 'app.posts.publish') {
        return true;
    }
    return null;
});
```
