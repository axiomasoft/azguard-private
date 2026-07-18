# Интеграция с Filament

Пакет `axioma-studio/azguard-filament` даёт первоклассный UI для управления
ролями и прямыми грантами, а также **конфигурируемую, без единой строчки
кода авторизацию** для ваших собственных ресурсов (Filament 5).

## Установка

```bash
composer require axioma-studio/azguard-filament
php artisan vendor:publish --tag=az-guard-filament-config
```

Зарегистрируйте плагин в провайдере панели Filament, указав панель AzGuard,
которой он должен управлять:

```php
use AzGuard\Filament\AzGuardPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugins([
        AzGuardPlugin::make()->forPanel('admin'),
    ]);
}
```

## Как работают права на ресурсы

Вы **не** добавляете код авторизации в свои ресурсы. Плагин сам обнаруживает
ресурсы и страницы панели и генерирует по одному праву на каждую способность
(ability), с ключом `{panel}.{resource}.{ability}` — например,
`admin.post.view_any`, `admin.post.create`.

Всё управляется из `config/az-guard-filament.php`: набор способностей, схема
ключей, источник, исключения и обход `super_admin`.

### Источники

Обнаруженные ключи всегда регистрируются в каталоге (поэтому появляются в UI
ролей и их можно выдавать). Источник (source) определяет, как именно
происходит *принуждение* (enforcement) и что генерируется:

- **`database`** (по умолчанию) — проверяет рантайм-gate; ничего не
  генерируется.
- **`enum`** — генерирует типизированный enum прав на каждый ресурс
  (проверка всё равно идёт через gate).
- **`policy`** — генерирует Laravel-политику на каждый ресурс; авторизацию
  берёт на себя нативный механизм Filament, а рантайм-gate отходит в
  сторону.

  ```bash
  php artisan guard:filament:generate --source=enum
  php artisan guard:filament:generate --source=policy
  ```

Просмотр без записи файлов:

```bash
php artisan guard:filament:generate --dry-run
```

### Enforcement

При `enforce = true` (по умолчанию) плагин заставляет Filament обращаться к
Gate при каждой проверке ресурса и отвечает на неё из прав пользователя
AzGuard. Пользователь видит ресурс и может с ним работать только если у него
есть соответствующее право — без единого базового класса, трейта или
политики в самом ресурсе. Роль с wildcard-правом `*` (например, роль
SuperAdmin) проходит любую проверку.

Чтобы отказаться от этого и управлять авторизацией самостоятельно, задайте
`enforce = false`.

### Страницы и виджеты — enforcement, а не просто скрытие

CRUD ресурсов (выше) проверяется через Gate. Кастомные **Page** и **Widget**
— нет: Filament маршрутизирует их через собственные статические проверки
`canAccess()` / `canView()`, которые никогда не обращаются к Gate, поэтому
рантайм-gate структурно не может их увидеть. AzGuard всё равно заносит право
`{panel}.{page}.view` / `{panel}.{widget}.view` в каталог для каждой
обнаруженной страницы/виджета, чтобы оно появилось в UI ролей — но на
«голой» кастомной странице или виджете это право существует только в
каталоге. Переопределение `shouldRegisterNavigation()` под это право скрывает
только ссылку в меню: страница остаётся доступной по своему URL, а разметка
виджета (и любые данные, которые он запрашивает) остаётся доступной на любой
странице, где он размещён. **Скрытие в навигации — не контроль доступа.**

Добавьте соответствующий трейт в любую кастомную страницу или виджет, чтобы
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

`HasAzGuardPage` переопределяет `canAccess()`; `HasAzGuardWidget` —
`canView()`. Оба проверяют то же самое право `{panel}.{page|widget}.view`,
которое уже объявлено в каталоге, относительно панели AzGuard, связанной
через `AzGuardPlugin::forPanel()`. Поскольку Filament вызывает `canAccess()`
при каждом mount *и* при каждом Livewire round-trip (а не только при
рендере ссылки в навигации), это закрывает разрыв в доступности по URL, а не
только боковую панель. Подключается явно, по классу — это не автоматика, по
аналогии с тем, что ресурсам нужен `enforce = true`, а страницам и виджетам —
трейт.

## Встроенные ресурсы управления

### RoleResource

Выводит список всех ролей (PHP-классы ролей + кастомные роли из БД) для
настроенной панели. Классовые роли можно только просматривать, DB-роли —
создавать/редактировать/удалять, а также назначать им права из пикера,
сгруппированного по группе прав.

### DirectGrantResource

Выводит список прямых грантов для любого пользователя на панели — создание
(пользователь + право + опциональный срок истечения) и отзыв.

### Страница Doctor

Страница **AzGuard Doctor** — это GUI-эквивалент `php artisan guard:doctor`:
она показывает конфликты каталога, роли, ссылающиеся на неизвестные права, и
карту панель → способность → обработчик. Значок в навигации становится
красным при ошибках и жёлтым при предупреждениях.

## Конфигурация

`config/az-guard-filament.php` — это поверхность подключения: классы,
привязанные к панели, таблицы, способности (`pages`/`widgets`), `exclude`,
`super_admin` и пути генерации всегда берутся из конфига. Поведенческие
опции — `enforce`, `source`, `abilities`, `key`, `case` — также имеют
fluent-альтернативу на плагине, читаемую в `register()`; конфиг остаётся
запасным вариантом, если fluent-вызов опущен:

```php
use AzGuard\Filament\AzGuardPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugins([
        AzGuardPlugin::make()
            ->forPanel('admin')
            ->enforce(true)
            ->source('database')
            ->abilities(['view_any', 'view', 'create', 'update', 'delete'])
            ->keyTemplate('{panel}.{resource}.{ability}')
            ->case('snake'),
    ]);
}
```

`AzGuardPlugin::make()` резолвится через контейнер (`app(static::class)`),
поэтому его можно подменить в тестах через
`app()->bind(AzGuardPlugin::class, ...)`.

Полный, снабжённый комментариями список опций — в
[`config/az-guard-filament.php`](https://github.com/axioma-studio/azguard).

## Совместимость

Требует Filament `^5.0`.

## Инвариант

Плагин авторизует только по правам, привязанным к панели, переданной в
`forPanel()`. Роли app-панели не действуют внутри админки Filament.
