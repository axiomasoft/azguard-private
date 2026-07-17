# AzGuard

[![Tests](https://github.com/axioma-studio/azguard/actions/workflows/tests.yml/badge.svg)](https://github.com/axioma-studio/azguard/actions/workflows/tests.yml)
[![Code Style](https://github.com/axioma-studio/azguard/actions/workflows/code-style.yml/badge.svg)](https://github.com/axioma-studio/azguard/actions/workflows/code-style.yml)
[![PHPStan](https://github.com/axioma-studio/azguard/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/axioma-studio/azguard/actions/workflows/static-analysis.yml)
[![Latest Version](https://img.shields.io/packagist/v/axioma-studio/azguard-core.svg?style=flat-square)](https://packagist.org/packages/axioma-studio/azguard-core)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](LICENSE)

**Code-first** RBAC для Laravel. Роли, права и панели — это PHP **enum'ы и классы**, а не магические строки: авторизация безопасна при рефакторинге, поддерживает автодополнение в IDE и ревьюится в pull request'ах.

> 🇬🇧 English version — [README.md](README.md).

---

## Почему AzGuard, а не Spatie?

| | AzGuard | spatie/laravel-permission |
|---|---|---|
| Источник правды | **Код** (enum'ы, классы) | Строки в БД |
| Права | **Enum-кейсы** / классы | Строки |
| Безопасность рефакторинга | ✅ переименование = рефакторинг IDE | ❌ grep по строкам |
| Multi-panel scope'ы | ✅ встроено | ❌ |
| PHP-атрибуты | ✅ `#[CheckPermission]`, `#[GateAbility]` | ❌ |
| Автодискавери политик | ✅ | ❌ |

---

## Установка

```bash
composer require axioma-studio/azguard-core
php artisan guard:install
```

`guard:install` публикует конфиг, выполняет миграции и печатает следующие шаги. Добавьте трейт к модели `User`:

```php
use AzGuard\Concerns\HasAzGuard;

class User extends Authenticatable
{
    use HasAzGuard;
}
```

---

## Быстрый старт (panel-first, enum-first)

### 1. Создайте панель

```bash
php artisan make:guard-panel App Documents
```

**Панель** — изолированный scope авторизации (`app`, `admin`, `api`…). Команда генерирует `app/Guards/App/` — провайдер панели, enum прав, политику и роль — и **сама регистрирует панель в `config/az-guard.php`**. Сгенерированный enum прав:

```php
namespace App\Guards\App\Documents\Permissions;

enum DocumentsPermission: string
{
    case ViewAny = 'documents.view_any';
    case View    = 'documents.view';
    case Create  = 'documents.create';
    case Update  = 'documents.update';
    case Delete  = 'documents.delete';
}
```

### 2. Объявите роль с enum-правами

```php
namespace App\Guards\App\Roles;

use App\Guards\App\Documents\Permissions\DocumentsPermission;
use AzGuard\Roles\BaseRole;

class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [
            DocumentsPermission::View,
            DocumentsPermission::Create,
            DocumentsPermission::Update,
        ];
    }
}
```

Никаких строк `"app.documents.view"` — панель сама префиксит enum-кейсы.

### 3. Зарегистрируйте код-роли в БД

```bash
php artisan guard:sync-roles
```

Команда зеркалит PHP-классы ролей в таблицу `roles`, чтобы их можно было назначать. Безопасна в CI/CD.

### 4. Назначайте роли и проверяйте права — по классу и enum

```php
// Назначение по классу — однозначно и безопасно при рефакторинге
$user->assignRole(EditorRole::class);
$user->hasRole(EditorRole::class);                 // true

// Проверка enum-кейсом — автоматически scope'ится к панели
$user->hasPermission(DocumentsPermission::View);   // true

// В Blade — тоже enum-aware
@azcan(DocumentsPermission::View)
    <a href="/documents">Документы</a>
@endazcan

// Нативный Gate Laravel принимает полный ключ с префиксом панели
$user->can('app.documents.view');                  // true
```

### 5. Супер-админ одной командой

```bash
php artisan guard:super-admin --user=1
```

Выдаёт wildcard-роль, которая через `Gate::before()` пропускает любую проверку.

---

## Права на классах (открытые наборы)

Для открытых / модульных наборов прав, где закрытый enum слишком жёсткий, реализуйте контракт `Permission` и ссылайтесь по `::class`:

```php
use AzGuard\Contracts\Permission;

final class UpdatePost implements Permission
{
    public static function ability(): string
    {
        return 'posts.update';
    }
}

$user->hasPermission(UpdatePost::class, 'app');    // -> "app.posts.update"
```

---

## Консольные команды

| Команда | Описание |
|---|---|
| `guard:install` | Публикация конфига + миграции (с подсказками) |
| `make:guard-panel {Panel} {Domain}` | Скаффолд панели (авто-регистрация в конфиге) |
| `make:guard-permission` | Генерация enum прав |
| `make:guard-role` | Генерация класса роли |
| `guard:sync-roles` | Зеркалит PHP-роли в таблицу `roles` |
| `guard:super-admin --user=` | Сделать пользователя супер-админом |
| `guard:doctor` | Диагностика конфигурации |
| `guard:list-permissions {panel?}` | Список зарегистрированных прав |
| `guard:cache-reset` | Сброс кэша прав |

`php artisan about` показывает версию AzGuard, панели и cache store.

---

## Кэш

По умолчанию — in-memory в рамках запроса. Для cross-request кэша через Redis:

```php
// config/az-guard.php
'cache' => [
    'store'           => 'redis',
    'expiration_time' => 3600,
],
```

Сброс: `php artisan guard:cache-reset`.

---

## Пакеты

- **`axioma-studio/azguard-core`** — роли, права, панели, прямые гранты
- **`axioma-studio/azguard-filament`** — админка на Filament
- **`axioma-studio/azguard-context`** — контекст multi-workspace / multi-site

---

## Тесты и качество

```bash
composer test      # Pest
composer check     # все гейты CI локально: стиль + статанализ + рефактор + тесты
composer fix       # авто-фикс стиля и рефакторинги
```

---

## Обновление

Это первый релиз `0.1`. См. [UPGRADING.md](UPGRADING.md) — заметки по миграции
будут появляться по мере выхода новых версий.

## Безопасность

При обнаружении уязвимости пишите на dv.vostrikov@gmail.com, а не в issue-трекер. См. [SECURITY.md](SECURITY.md).

## Лицензия

MIT — см. [LICENSE](LICENSE).
