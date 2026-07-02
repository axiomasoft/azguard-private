# Обзор: Основы

Этот раздел показывает ключевые примитивы AzGuard: проверка прав, назначение ролей, прямые гранты и интеграция с Laravel Gate.

## Проверка прав

```php
use AzGuard\Facades\AzGuard;
use Illuminate\Support\Facades\Gate;
use App\Guards\App\Posts\Permissions\PostsPermission;

// Проверка через модель — enum-кейс привязывается к панели автоматически
$user->hasPermission(PostsPermission::View);         // true / false
$user->hasPermission('app.posts.view');               // строка — полный ключ с префиксом панели

// Проверка роли — по классу (предпочтительно), по имени тоже работает
$user->hasRole(EditorRole::class);                    // true / false
$user->hasRole('editor');                             // по имени тоже работает

// Нативный Laravel Gate — нужен полный строковый ключ с префиксом панели.
// НЕ передавайте сюда «голый» enum: Laravel приведёт его к ->value без
// префикса панели, и он никогда не совпадёт с панельным ключом.
Gate::allows('app.posts.view');                       // true / false
// Либо выведите ключ из enum «без опечаток»:
Gate::allows(AzGuard::permission('app', PostsPermission::View));
$this->authorize('update', $post);                    // 403 если запрещено

// Blade
@azcan(PostsPermission::Edit) ... @endazcan           // директива AzGuard — понимает enum
@can('app.posts.edit') ... @endcan                    // нативный @can — полный ключ с префиксом панели
@azrole('editor') ... @endazrole
```

## Назначение / снятие роли

```php
// Назначить — по классу (предпочтительно: однозначно и безопасно при рефакторинге)
$user->assignRole(EditorRole::class);
$user->assignRole('editor');                          // по имени тоже работает
$user->assignRole(EditorRole::class, ModeratorRole::class);

// Снять
$user->removeRole(EditorRole::class);
$user->removeRole('editor');                          // по имени тоже работает

// Все роли пользователя
$user->roles;   // Collection (отношение)
```

## PHP 8 Attributes

Декларативная защита методов контроллера:

```php
// Проверка перед выполнением метода
#[CheckPermission(PostsPermission::View)]
public function index(): Response { ... }

// Проверка с route model binding
#[CheckPermission(permission: PostsPermission::Edit, arguments: ['post'])]
public function edit(Post $post): Response { ... }

// Пропустить проверку (super-admin, внутренние CLI-команды)
#[SkipGuardCheck]
public function internalSync(): void { ... }
```

## Прямые гранты

```php
// Выдать одно право на 1 час (ttl в секундах)
AzGuard::forUser($user)->on('app')->ttl(3600)->grant(ReportsPermission::Export);

// Или напрямую через модель (panelId обязателен)
$user->grant(ReportsPermission::Export, 'app', now()->addHour());

// Отозвать
$user->revoke(ReportsPermission::Export, 'app');
```

→ [Подробнее о прямых грантах](/ru/basic-usage/direct-grants)

## Запросы по ролям

Роли — это обычное отношение `roles()`, поэтому фильтруйте через `whereHas` / `whereDoesntHave`:

```php
// Все пользователи с ролью editor
User::whereHas('roles', fn ($q) => $q->where('name', 'editor'))->get();

// Все пользователи без какой-либо роли
User::whereDoesntHave('roles')->get();
```

→ [Разрешения](/ru/basic-usage/permissions) · [Роли](/ru/basic-usage/roles) · [Blade-директивы](/ru/basic-usage/blade-directives)
