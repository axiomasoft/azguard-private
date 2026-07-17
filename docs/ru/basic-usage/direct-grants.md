# Прямые гранты

::: warning Роли предпочтительнее прямых грантов
Прямые гранты — это механизм **исключения**, а не основной паттерн контроля
доступа. Правильное поведение по умолчанию — назначать права ролям, а роли —
пользователям. Используйте прямые гранты только когда конкретному
пользователю нужно временное или разовое право, ради которого не стоит
создавать отдельную роль.

Полное руководство — [Роли vs Разрешения](/ru/best-practices/best-practices).
:::

Прямые гранты позволяют выдать право **напрямую пользователю**, не создавая
для этого роль. Типичные сценарии:

- Временный доступ (бета-фича, экспорт на ограниченное время)
- Разовое исключение для конкретного пользователя
- Feature-флаги, привязанные к отдельным аккаунтам

## Когда использовать прямые гранты

| Ситуация | Рекомендация |
|---|---|
| Право нужно только одному пользователю | ✅ Прямой грант |
| Пользователю нужен временный доступ (истекает через N часов) | ✅ Прямой грант с TTL |
| Право нужно нескольким пользователям | ❌ Создайте роль |
| Право — часть повседневных обязанностей пользователя | ❌ Назначьте роль |
| Вы выдаёте одно и то же право 5+ пользователям | ❌ Пора создать роль |

## Подключение трейта

Добавьте `HasDirectGrants` к модели пользователя вместе с `HasAzGuard`:

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Concerns\HasDirectGrants;

class User extends Authenticatable
{
    use HasAzGuard, HasDirectGrants;
}
```

::: tip
`HasDirectGrants` расширяет `hasPermission()`: теперь он проверяет и роли, и
прямые гранты. Никаких других изменений в коде не требуется.
:::

## Выдача права

### Fluent API

```php
use AzGuard\Facades\AzGuard;

// Бессрочный
AzGuard::forUser($user)
    ->on('app')
    ->grant(DocumentsPermission::Export);

// С TTL в 1 час (секунды)
AzGuard::forUser($user)
    ->on('app')
    ->ttl(3600)
    ->grant(DocumentsPermission::Export);

// Короткая форма
AzGuard::grant($user, DocumentsPermission::Export, 'app', ttl: 3600);

// Напрямую на модели (с опциональной датой истечения)
$user->grant(DocumentsPermission::Export, 'app');
$user->grant(DocumentsPermission::Export, 'app', now()->addHour());
```

::: info Идемпотентность
Вызов `grant()` для уже выданного права обновляет `expires_at`, не создавая
дубликат. Безопасно вызывать многократно.
:::

### Artisan

```bash
# Бессрочный
php artisan guard:grant {user-id} {permission} {panel}

# С TTL (секунды)
php artisan guard:grant 42 app.documents.export app --ttl=3600

# Другая модель
php artisan guard:grant 7 admin.reports.view admin --model=App\\Models\\Admin
```

## Отзыв гранта

```php
// Одно право — enum
AzGuard::forUser($user)->on('app')->revoke(DocumentsPermission::Export);
AzGuard::revoke($user, DocumentsPermission::Export, 'app');

// Все гранты в панели
AzGuard::forUser($user)->on('app')->revokeAll();
```

```bash
# Artisan
php artisan guard:revoke-grant 42 app.documents.export app

# Отозвать все гранты в панели (аргумент permission игнорируется при --all)
php artisan guard:revoke-grant 42 - app --all --force
```

## Проверка гранта

```php
// На модели User — enum или строка, enum предпочтителен
$user->hasGrant(DocumentsPermission::Export);
$user->hasGrant(DocumentsPermission::Export, 'app');

// Через Laravel Gate — передавайте полный строковый ключ (опционально [key, panel])
Gate::allows('direct-grant', 'app.documents.export');
Gate::allows('direct-grant', ['app.documents.export', 'app']);

// Список грантов в панели
$grants = AzGuard::forUser($user)->on('app')->grants();
$grants = AzGuard::grants($user, 'app');
```

## Blade

```blade
{{-- Передавайте полный ключ с префиксом панели --}}
@azdirect('app.documents.export')
    <button>Экспорт</button>
@endazdirect

{{-- Панель явным вторым аргументом --}}
@azdirect('app.documents.export', 'app')
    <button>Экспорт</button>
@endazdirect
```

## Route middleware

```php
// azguard.grant:{permission},{panel} — middleware требует полный ключ
Route::get('/export', ExportController::class)
    ->middleware('azguard.grant:app.documents.export,app');

// Панель подставляется из текущей панели AzGuard, если опущена
Route::get('/export', ExportController::class)
    ->middleware('azguard.grant:app.documents.export');
```

| Ситуация | HTTP-статус |
|---|---|
| Не аутентифицирован | 401 |
| Гранта нет или он истёк | 403 |
| Грант активен | пропускает дальше |

## TTL и истечение срока

Грант с `expires_at < now()` считается неактивным во всех проверках. Истёкшие
записи очищает планировщик:

```php
// bootstrap/app.php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('guard:prune-grants')->daily();
})
```

```bash
php artisan guard:prune-grants
php artisan guard:prune-grants --panel=app
```

## События

| Событие | Когда диспатчится |
|---|---|
| `GrantGiven` | После каждого вызова `grant()` |
| `GrantRevoked` | После каждого вызова `revoke()` / `revokeAll()` (`permissionKey` равен `*` для `revokeAll()`) |

```php
use AzGuard\Events\GrantGiven;
use AzGuard\Events\GrantRevoked;

Event::listen(GrantGiven::class, function (GrantGiven $event): void {
    Log::info("Grant [{$event->permissionKey}] issued to user #{$event->user->getAuthIdentifier()}");
});

Event::listen(GrantRevoked::class, function (GrantRevoked $event): void {
    // например, инвалидировать кеш API
});
```

## Шпаргалка

| Метод | Код |
|---|---|
| Fluent grant | `AzGuard::forUser($u)->on('app')->ttl(3600)->grant(DocumentsPermission::Export)` |
| Короткая форма grant | `AzGuard::grant($u, DocumentsPermission::Export, 'app', ttl: 3600)` |
| Artisan grant | `php artisan guard:grant {id} {perm} {panel}` |
| Отзыв | `AzGuard::forUser($u)->on('app')->revoke(DocumentsPermission::Export)` |
| Проверка (модель) | `$user->hasGrant(DocumentsPermission::Export, 'app')` |
| Проверка (Gate) | `Gate::allows('direct-grant', 'app.documents.export')` |
| Blade | `@azdirect('app.documents.export') ... @endazdirect` |
| Middleware | `->middleware('azguard.grant:app.documents.export,app')` |
