# Быстрый старт

От нуля до работающей проверки прав меньше чем за 5 минут.

## Требования

- PHP 8.3+
- Laravel 11.x, 12.x или 13.x
- База данных, поддерживаемая Laravel (MySQL, PostgreSQL, SQLite)

## 1. Установка

```bash
composer require axioma-studio/azguard-core
php artisan vendor:publish --tag=az-guard-config
php artisan migrate
```

Миграция создаёт таблицы: `roles`, `model_has_roles`, `model_has_scopes`, `az_guard_role_permissions` и `az_direct_grants`.

## 2. Добавьте трейт в модель User

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardUser;

class User extends Authenticatable implements AzGuardUser
{
    use HasAzGuard;
}
```

Трейт композирует `HasRoles` и `HasPermissions`, добавляя `hasPermission()`, `checkPermission()`, `assignRole()`, `removeRole()`, `syncRoles()` и `flushPermissions()`.

## 3. Зарегистрируйте панель

**Панель** — это изолированное пространство имён прав: `app`, `admin`, `api` и т. д.
Создайте панель-провайдер и укажите его в `config/az-guard.php`:

```php
// app/Guards/App/AppGuardPanelProvider.php
namespace App\Guards\App;

use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\Panel;
use App\Guards\App\Documents\Permissions\DocumentsPermission;
use App\Guards\App\Users\Permissions\UsersPermission;

class AppGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->permissionEnums([
                DocumentsPermission::class,
                UsersPermission::class,
            ]);
    }
}
```

```php
// config/az-guard.php
'panels' => [
    \App\Guards\App\AppGuardPanelProvider::class,
],
```

## 4. Создайте enum прав

```bash
php artisan make:guard-permission App Documents
```

```php
// app/Guards/App/Documents/Permissions/DocumentsPermission.php
namespace App\Guards\App\Documents\Permissions;

enum DocumentsPermission: string
{
    case View   = 'documents.view';
    case Create = 'documents.create';
    case Edit   = 'documents.edit';
    case Delete = 'documents.delete';
}
```

Значения enum задаются без префикса панели — панель добавляет его сама. Полный ключ Gate — `{panel}.{permission_value}` → `app.documents.view`.

## 5. Создайте роль

```bash
php artisan make:guard-role
```

```php
// app/Guards/App/Roles/EditorRole.php
namespace App\Guards\App\Roles;

use App\Guards\App\Documents\Permissions\DocumentsPermission;
use AzGuard\Roles\BaseRole;

class EditorRole extends BaseRole
{
    public function getLevel(): int { return 10; }

    public function permissions(): array
    {
        return [
            DocumentsPermission::View,
            DocumentsPermission::Create,
            DocumentsPermission::Edit,
        ];
    }
}
```

`BaseRole` выводит имя роли из имени класса (`EditorRole` → `editor`). `permissions()` возвращает **случаи enum** — панель автоматически скоупит каждый из них, так что префикс `"app."` прописывать не нужно. Перед назначением роли выполните `php artisan guard:sync-roles`, чтобы отразить класс роли в таблице `roles`.

## 6. Назначьте и проверьте

```php
// Назначение по классу — однозначно и безопасно при рефакторинге
$user->assignRole(EditorRole::class);
$user->assignRole('editor');                       // по имени тоже работает

// ✅ Проверка через enum-кейс — автоматически скоупится к панели
$user->hasPermission(DocumentsPermission::View);   // true

// Нативный Gate Laravel использует полный ключ с префиксом панели
Gate::allows('app.documents.view');                // true (фасад Gate)
request()->user()->can('app.documents.view');      // true (хелпер Auth)
```

::: tip Enum-кейсы vs строковые ключи
Enum-кейс (`DocumentsPermission::View`) автоматически скоупится к панели и безопасен при рефакторинге. Строка должна быть полным ключом с префиксом панели (`'app.documents.view'`). Gate Laravel (`Gate::allows()`, `$user->can()`) работает именно с полным строковым ключом.
:::

## Проверка настройки

```bash
php artisan guard:doctor
```

Доктор проверяет:
- Отсутствие дублирующихся Gate-абилок в разных классах политик
- Наличие у каждого enum-кейса права соответствующего метода политики с `#[GateAbility]`
- Что роли ссылаются только на известные права
- Отсутствие «сиротских» политик (классов без методов `#[GateAbility]`)
- Отсутствие устаревших значений `scope_class` в `model_has_scopes`

## Дальнейшие шаги

- [Быстрый старт (headless)](/ru/introduction/headless-quick-start) — минимальный путь для встроенного/headless-потребителя (без Filament)
- [Панели](/ru/advanced/panels) — понимание изоляции `app` vs `admin`
- [Права](/ru/basic-usage/permissions) — соглашения об именовании, `#[RoleOnly]`, frontend-абилки
- [Роли](/ru/basic-usage/roles) — статические и динамические (хранимые в БД) роли
- [HTTP-доступ](/ru/basic-usage/http-access) — `#[CheckPermission]` на контроллерах и middleware
- [Прямые гранты](/ru/basic-usage/direct-grants) — права для конкретного пользователя без роли
- [Супер-администратор](/ru/basic-usage/super-admin) — обход проверок через wildcard-доступ
