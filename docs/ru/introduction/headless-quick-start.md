# Быстрый старт (headless)

Минимальный путь для встраиваемого/headless-потребителя — моста, библиотеки,
сервиса без админ-панели — которому нужен только `$user->can()` / курированный
список прав для фронтенда, без глав про Filament.

::: tip Fail-closed, а не «без панелей»
У AzGuard нет режима «без панели» — каждая проверка по-прежнему резолвится
через зарегистрированную панель (D14: YAGNI, fail-closed сохраняется). Этот
гайд показывает **минимальный**, но полный сетап, а не обход панелей.
`guard:doctor` печатает onboarding-подсказку при 0 зарегистрированных
панелей — пустой сетап никогда не путается с поломанным.
:::

## 1. Установка

```bash
composer require axioma-studio/azguard-core
php artisan vendor:publish --tag=az-guard-config
php artisan migrate
```

## 2. Реализуйте `AzGuardUser`

```php
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardUser;

class User extends Authenticatable implements AzGuardUser
{
    use HasAzGuard;
}
```

## 3. Одна минимальная панель

Достаточно одной панели — Filament-ресурс или UI под неё не нужны.

```php
// app/Guards/Api/ApiGuardPanelProvider.php
namespace App\Guards\Api;

use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\Panel;
use App\Guards\Api\Permissions\ApiPermission;

class ApiGuardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('api')
            ->path('api')
            ->permissionEnums([
                ApiPermission::class,
            ]);
    }
}
```

```php
// config/az-guard.php
'panels' => [
    \App\Guards\Api\ApiGuardPanelProvider::class,
],
```

```php
// app/Guards/Api/Permissions/ApiPermission.php
namespace App\Guards\Api\Permissions;

enum ApiPermission: string
{
    case ReadDocuments  = 'documents.read';
    case WriteDocuments = 'documents.write';
}
```

## 4. Выдайте право и проверьте — без Filament, без контроллера

```php
// Прямой грант — без класса роли, без записи в таблице roles
AzGuard::forUser($user)->on('api')->grant(ApiPermission::ReadDocuments);

// Проверка — обычный PHP, работает в job'е, artisan-команде, queue-слушателе
$user->hasPermission(ApiPermission::ReadDocuments); // true

// Курированная boolean-проекция для не-Filament фронтенда/API-ответа
AzGuard::abilitiesFor(
    user: $user,
    panelId: 'api',
    keys: [
        ApiPermission::ReadDocuments->value,
        ApiPermission::WriteDocuments->value,
    ],
);
```

Полную fluent-грамматику грантов см. [Прямые гранты](/ru/basic-usage/direct-grants),
контракт `abilitiesFor()` — [Права на фронтенде](/ru/basic-usage/abilities-frontend).

## 5. Проверка

```bash
php artisan guard:doctor
```

`guard:doctor` работает без Filament — он проверяет зарегистрированные
панели, enum'ы и роли. На свежей установке с 0 панелей команда печатает
onboarding-подсказку со ссылкой на эту страницу вместо тихого прохождения.

## Дальше

- [Панели](/ru/advanced/panels) — модель изоляции панелей
- [Прямые гранты](/ru/basic-usage/direct-grants) — полная грамматика грантов
- [Права на фронтенде](/ru/basic-usage/abilities-frontend) — курированные boolean-проекции
- [Быстрый старт](/ru/introduction/quick-start) — полный путь, включая роли и Filament
