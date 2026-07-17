# Интеграция с Filament

AzGuard предоставляет первоклассную интеграцию с [Filament v5](https://filamentphp.com).

## Установка

```bash
composer require axioma-studio/azguard-filament
```

## Регистрация плагина

```php
// app/Providers/Filament/AdminPanelProvider.php
use AzGuard\Filament\AzGuardPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->authGuard('admin')
        ->plugin(
            AzGuardPlugin::make()
                ->forPanel('admin')  // id панели AzGuard из каталога прав
        );
}
```

Плагин добавляет ресурсы управления ролями и прямыми грантами, а также страницу
диагностики (`DoctorPage`).

## Авторизация без кода

При `enforce => true` (по умолчанию) плагин авторизует **каждый** обнаруженный
ресурс по сгенерированному праву — никакого кода в самих ресурсах не требуется.
AzGuard отключает «shortcut» Filament по наличию политики и отвечает на проверки
Gate из прав пользователя AzGuard.

```php
// config/az-guard-filament.php
return [
    'panel'   => 'admin',     // панель AzGuard, питающая каталог прав
    'enforce' => true,        // авторизовать ресурсы автоматически
    'source'  => 'database',  // 'database' | 'enum' | 'policy'
];
```

- `database` — права проверяет рантайм-gate, ничего не генерируется;
- `enum` — `guard:filament:generate` пишет enum прав на каждый ресурс;
- `policy` — `guard:filament:generate` пишет Laravel-политику на каждый ресурс.

## Генерация enum / политик

```bash
php artisan guard:filament:generate
php artisan guard:filament:generate --source=enum --panel=admin --dry-run
```

## Страницы и виджеты — enforcement, а не просто скрытие в навигации

CRUD ресурсов (выше) проверяется через Gate. Кастомные **Page** и **Widget** —
нет: Filament маршрутизирует их через собственные статические проверки
`canAccess()` / `canView()`, которые никогда не идут через Gate, так что
рантайм-gate структурно не может их увидеть. AzGuard всё равно заносит право
`{panel}.{page}.view` / `{panel}.{widget}.view` в каталог для каждой
обнаруженной страницы/виджета — оно появляется в UI ролей, — но на «голой»
странице/виджете это право существует только в каталоге. Если скрывать пункт
меню через `shouldRegisterNavigation()` по этому праву, это прячет только
ссылку: страница остаётся доступной по прямому URL, а разметка и данные
виджета — на любой странице, где он размещён. **Скрытие в навигации — не
контроль доступа.**

Добавьте соответствующий трейт в кастомную страницу/виджет, чтобы
каталогизированное право реально проверялось:

```php
use AzGuard\Filament\Concerns\HasAzGuardPage;
use Filament\Pages\Page;

class Settings extends Page
{
    use HasAzGuardPage;
}
```

```php
use AzGuard\Filament\Concerns\HasAzGuardWidget;
use Filament\Widgets\Widget;

class RevenueChart extends Widget
{
    use HasAzGuardWidget;
}
```

`HasAzGuardPage` переопределяет `canAccess()`, `HasAzGuardWidget` —
`canView()`. Оба проверяют то же право `{panel}.{page|widget}.view`, которое
уже объявлено в каталоге, относительно панели AzGuard, связанной через
`AzGuardPlugin::forPanel()`. Filament вызывает `canAccess()` при каждом mount
и при каждом Livewire round-trip (не только при рендере пункта меню) — значит
закрывается доступность по URL, а не только боковая панель. Подключается
явно, по классу — это не автоматика, по аналогии с тем, что ресурсам нужен
`enforce = true`, а страницам и виджетам — трейт.

## Навигация с учётом прав

```php
// Пункты меню скрываются при отсутствии прав
NavigationItem::make()
    ->label('Пользователи')
    ->url('/admin/users')
    ->visible(fn () => auth()->user()?->hasPermission(UsersPermission::View, 'admin'))
```

Это скрывает только пункт меню — доступ к URL страницы не проверяет. Для
реального контроля доступа страницы используйте `HasAzGuardPage` (см. выше).

→ [Несколько Guards](/ru/basic-usage/multiple-guards)
