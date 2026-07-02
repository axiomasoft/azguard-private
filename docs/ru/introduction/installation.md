# Установка

## Требования

| Зависимость | Версия |
|---|---|
| PHP | ≥ 8.3 |
| Laravel | 11.x или 12.x |
| Laravel Octane | совместим (stateless) |

## Установка через Composer

```bash
composer require axioma-studio/azguard-core
```

## Публикация конфигурации

```bash
php artisan vendor:publish --tag=az-guard-config
```

Это создаст `config/az-guard.php`.

## Запуск миграций

Миграции AzGuard загружаются автоматически — публиковать их не нужно:

```bash
php artisan migrate
```

Миграции создадут таблицы:
- `roles` — каталог ролей
- `model_has_roles` — связки model → role
- `az_direct_grants` — прямые гранты с TTL

## Добавление трейта

Добавьте `HasAzGuard` в вашу модель User (или любую другую модель, которой нужны роли):

```php
use AzGuard\Concerns\HasAzGuard;

class User extends Authenticatable
{
    use HasAzGuard;
}
```

## Конфигурация панелей

В `config/az-guard.php` укажите PanelProvider'ы вашего приложения (FQCN классов, расширяющих `AzGuard\PanelProvider`):

```php
return [
    'panels' => [
        App\Guards\App\AppGuardPanelProvider::class,
        App\Guards\Admin\AdminGuardPanelProvider::class,
        App\Guards\Api\ApiGuardPanelProvider::class,
    ],
];
```

::: tip Octane
AzGuard не хранит глобального состояния. Работает с Laravel Octane и Kubernetes без дополнительной настройки.
:::
