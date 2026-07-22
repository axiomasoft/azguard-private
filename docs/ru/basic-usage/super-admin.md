# Супер-администратор

Супер-админ — это пользователь, который обходит все проверки прав. AzGuard реализует это через **wildcard-грант** — специальный `PermissionSet`, который возвращает `true` для любого ключа разрешения.

## Вариант 1: Gate before-hook (рекомендуется)

Это самый простой и наиболее идиоматичный для Laravel подход:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::before(function ($user, $ability) {
        if ($user->isSuperAdmin()) {
            return true;  // прерывает все последующие проверки
        }
    });
}
```

Определите `isSuperAdmin()` в модели User:

```php
public function isSuperAdmin(): bool
{
    return $this->hasRole(SuperAdminRole::class);  // по классу (предпочтительно); 'super-admin' по имени тоже работает
}
```

## Вариант 2: Wildcard-роль

Создайте роль, которая выдаёт wildcard-набор разрешений:

```php
use AzGuard\Roles\BaseRole;

class SuperAdminRole extends BaseRole
{
    public function getName(): string { return 'super-admin'; }
    public function getLevel(): int   { return 999; }

    public function permissions(): array
    {
        return ['*'];  // wildcard — выдаёт всё в рамках этой панели
    }
}
```

Зарегистрируйте её на панели через `roleClasses([SuperAdminRole::class])` в панель-провайдере.

Затем назначьте роль пользователю как обычно. Сначала выполните `php artisan guard:sync-roles`, чтобы класс-роль попал в БД перед назначением:

```php
$user->assignRole(SuperAdminRole::class);     // по классу (предпочтительно); 'super-admin' по имени тоже работает

$user->hasPermission(DocumentsPermission::View);  // true — enum-кейс, привязан к панели
$user->hasPermission('app.anything.at.all');      // true — полный ключ с префиксом панели
```

## Вариант 3: Прямой wildcard-грант

```php
use AzGuard\Facades\AzGuard;

// Выдать доступ супер-админа на 24 часа (TTL в секундах)
AzGuard::forUser($user)
    ->on('app')
    ->ttl(86400)
    ->grant('*');
```

## Проверка в тестах

```php
// Быстро сделать любого пользователя супер-админом в тесте
$user->assignRole(SuperAdminRole::class);     // 'super-admin' по имени тоже работает
$this->actingAs($user);

$this->get('/admin/users')->assertOk();
```

## Область действия wildcard

Wildcard-доступ действует **в рамках одной панели** — wildcard роли покрывает только ту панель, на которой зарегистрирован класс роли. Роль супер-админа, зарегистрированная на панели `app`, автоматически не даёт доступ к `admin`:

```php
$user->assignRole(SuperAdminRole::class);  // роль зарегистрирована на панели 'app'

$user->hasPermission('app.documents.delete');  // true
$user->hasPermission('admin.users.delete');    // false — другая панель
```

Чтобы дать доступ сразу ко всем панелям, зарегистрируйте wildcard-роль на каждой панели либо используйте подход с Gate before-hook, который выполняется до разрешения панели.
