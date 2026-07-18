# Entity-scoped роли

Entity-scoped роли позволяют назначить роль пользователю **для конкретного экземпляра модели**. Пользователь может быть `editor` в проекте A и не иметь никакой роли в проекте B.

Это дополняет глобальные роли, а не заменяет их.

::: tip
Нужен request-scoped переключатель «текущий workspace/tenant», а не персистентная
роль на записи? См. [Context или scope?](/ru/advanced/context#context-or-scope) в
доках `azguard/context`.
:::

## Подключение

Добавьте `HasScopedRoles` к любой Eloquent-модели, которая должна поддерживать назначение scoped-ролей:

```php
use AzGuard\Concerns\HasScopedRoles;

class Project extends Model
{
    use HasScopedRoles;
}
```

Модель `User` должна уже использовать `HasAzGuard`.

## Назначение и снятие scoped-ролей

```php
// Назначить
$user->assignScopedRole(EditorRole::class, $project);

// Снять
$user->removeScopedRole(EditorRole::class, $project);

// Проверить
$user->hasScopedRole(EditorRole::class, $project); // bool
```

## Проверка scoped-права

`hasScopedPermission()` резолвит права в таком порядке:

1. **Wildcard** — если у любой глобальной роли есть `['*']`, вернуть `true`.
2. **Глобальные роли** — сначала проверяются права из `assignRole()`.
3. **Scoped-роли** — затем права из `assignScopedRole($entity)` для данной сущности.

```php
if ($user->hasScopedPermission(DocumentsPermission::Edit, $project)) {
    // пользователь может редактировать именно этот проект
}
```

Интеграция с Gate автоматически использует scoped-резолюцию, если вторым аргументом передана сущность:

```php
Gate::allows('app.documents.edit', $project); // использует scoped-резолюцию
```

## Сценарии использования

| Сценарий | Scoped-роль |
|---|---|
| Мультитенантные проекты | `editor`, привязанный к `Project` |
| Управление командой | `team-admin`, привязанный к `Team` |
| Ревью документов | `reviewer`, привязанный к `Document` |
| Владение ресурсом | `owner`, привязанный к любой Eloquent-модели |

## Кеш

Кеш scoped-прав сбрасывается автоматически при вызове `assignScopedRole()` и `removeScopedRole()`. Для ручного сброса:

```bash
php artisan guard:cache-reset
```
