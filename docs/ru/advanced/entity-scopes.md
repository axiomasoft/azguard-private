# Entity Scopes

Entity Scopes позволяют ограничивать права конкретными экземплярами модели — например, пользователь может редактировать только посты **своего** проекта.

## Подключение

Добавьте трейт `HasScopedRoles` к модели пользователя (в дополнение к `HasAzGuard`):

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Concerns\HasScopedRoles;

class User extends Authenticatable
{
    use HasAzGuard;
    use HasScopedRoles;
}
```

## Назначение роли в рамках сущности

```php
// Роль editor только в пределах конкретного проекта
$user->assignScopedRole('editor', $project);

$user->hasScopedRole('editor', $project);   // true
$user->removeScopedRole('editor', $project);
```

## Проверка права в рамках сущности

```php
class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        // Право, выданное scoped-ролью в пределах проекта поста
        return $user->hasScopedPermission(PostsPermission::Edit, $post->project);
    }
}
```

Со строковым ключом панель берётся из первого сегмента (`app.posts.edit` → `app`).
Для enum-прав передавайте панель явно:

```php
$user->hasScopedPermission(PostsPermission::Edit, $project, 'app');
```

## Кастомный scope для фильтрации запросов

`ScopeInterface` применяется к Eloquent-запросу и ограничивает выборку
сущностями, к которым у пользователя есть доступ:

```php
use AzGuard\Contracts\ScopeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OwnedByUserScope implements ScopeInterface
{
    public function apply(Builder $builder, Model $user, ?Model $entity): void
    {
        $builder->where('user_id', $user->getKey());
    }
}
```

`apply()` — панель-условно: при АКТИВНОЙ текущей панели scope с явной `panel_id`,
не совпадающей с ней, не применяется (изоляция между панелями); scope с
`panel_id === null` применяется под любой панелью (back-compat). Когда текущая
панель НЕ установлена (норма для Filament-запросов и роутов без middleware
`azguard.panel`), применяются ВСЕ scopes, как если бы панелей не было вовсе.
