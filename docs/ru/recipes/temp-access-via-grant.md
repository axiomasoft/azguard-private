# Рецепт: временный доступ через прямой грант

Прямые гранты позволяют выдать пользователю одно право на фиксированный
срок, не меняя его роль и не создавая отдельную кастомную роль.

## Сценарии использования

- Подрядчику нужен read-доступ к конкретному домену на две недели
- Инженеру поддержки нужен доступ на удаление во время расследования бага
- Ревьюеру нужен доступ на публикацию для разового релиза

## Выдача гранта

```php
use AzGuard\Facades\AzGuard;
use App\Guards\App\Documents\Permissions\DocumentsPermission;

// Fluent builder — ttl() принимает секунды (две недели)
AzGuard::forUser($contractor)
    ->on('app')
    ->ttl(14 * 24 * 3600)
    ->grant(DocumentsPermission::View);

// Или передать явную дату истечения методу модели:
$contractor->grant(DocumentsPermission::View, 'app', now()->addWeeks(2));
```

## Досрочный отзыв

```php
use AzGuard\Facades\AzGuard;

// Отозвать конкретный грант (удаляет запись, сбрасывает кеш пользователя)
AzGuard::forUser($contractor)->on('app')->revoke(DocumentsPermission::View);

// Или напрямую на модели:
$contractor->revoke(DocumentsPermission::View, 'app');
```

## Через Filament

Откройте **Direct Grants → Create**, выберите пользователя и право из
выпадающих списков, задайте дату истечения и сохраните. Грант становится
активным немедленно.

Чтобы отозвать: найдите грант в списке и нажмите **Revoke**.

## Через artisan (dev / сидинг)

```bash
# guard:grant {user-id} {permission} {panel} [--ttl=<seconds>]
php artisan guard:grant 42 app.documents.view app --ttl=1209600
```

## Инвалидация кеша

AzGuard автоматически очищает кеш прав пользователя при создании или
отзыве гранта. Ручной сброс кеша не требуется.

::: tip
Гранты логируются с полями `granted_by` и `reason`. Используйте их, чтобы
поддерживать содержательный аудиторский след для целей compliance.
:::
