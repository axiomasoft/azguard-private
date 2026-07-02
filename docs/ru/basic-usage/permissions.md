# Разрешения

Права в AzGuard — это **PHP enum-кейсы**, а не записи в базе данных. Они
живут в кодовой базе, проходят ревью в PR и всегда синхронизированы с
логикой приложения.

## Соглашение об именовании

Каждый ключ права следует паттерну `{panel}.{resource}.{action}`:

```
app.documents.view
app.documents.create
admin.users.delete
api.reports.export
```

Префикс панели (`app.`) добавляется автоматически AzGuard-ом на основании
панели, на которой зарегистрирован enum. Внутри enum вы объявляете только
`documents.view`.

## Определение прав

```php
use AzGuard\Attributes\RoleOnly;

enum DocumentsPermission: string
{
    case View   = 'documents.view';

    case Create = 'documents.create';

    case Edit   = 'documents.edit';

    // Назначается ролям, но исключён из Gate / экспорта прав на фронтенд
    #[RoleOnly]
    case Delete = 'documents.delete';
}
```

Enum — это обычный backed enum, он не реализует никакого интерфейса.
Зарегистрируйте его на панели через `Panel::permissionEnums([DocumentsPermission::class])` —
панель добавит префикс к каждому значению.

## Атрибуты

| Атрибут | Эффект |
|---|---|
| `#[RoleOnly]` | Кейс — валидное право для ролей, но исключён из генерируемого набора abilities для фронтенда / Gate-поверхности |
| _(нет)_ | Обычное право — резолвится везде |

## Генерация через Artisan

```bash
php artisan make:guard-permission {Panel} {Domain} {Case?}

php artisan make:guard-permission App Documents
php artisan make:guard-permission Admin Users
```

Команда создаёт (или добавляет кейс в) `{Domain}Permission` в директории
`Permissions/` панели. Передайте опциональное имя кейса, чтобы добавить один
кейс; опустите его, чтобы создать сам enum. Зарегистрируйте enum в провайдере
панели через `permissionEnums([...])`.

## Проверка прав

AzGuard предоставляет несколько методов проверки прав. Используйте подходящий
для контекста:

```php
// ── На модели User (через HasAzGuard) ──────────────────────────────────────

// Возвращает true/false — повседневная проверка
$user->hasPermission(DocumentsPermission::View);    // enum — привязан к своей панели
$user->hasPermission('app.documents.view');         // полный строковый ключ тоже работает

// Проверка нескольких прав самостоятельно
$hasAny = $user->hasPermission(DocumentsPermission::Edit)
    || $user->hasPermission(DocumentsPermission::Delete);

$hasAll = $user->hasPermission(DocumentsPermission::View)
    && $user->hasPermission(DocumentsPermission::Edit);

// Все резолвленные ключи для панели (коллекция строк)
$user->permissions('app');     // Collection<int, string>

// Тихая проверка — никогда не бросает исключений, false при отсутствующем/истёкшем гранте
$user->checkPermission(DocumentsPermission::View);
```

```php
// ── Через Laravel Gate (регистрируется автоматически через Gate::before) ───

Gate::allows('app.documents.view');          // bool
Gate::check('app.documents.view');           // алиас
$this->authorize('app.documents.view');      // бросает AuthorizationException при отказе
Gate::authorize('app.documents.view');       // то же, доступно вне контроллеров

// С моделью (проходит через вашу Policy)
Gate::allows('app.documents.edit', $document);
$this->authorize('app.documents.edit', $document);
```

```php
// ── В Blade ──────────────────────────────────────────────────────────────

@can('app.documents.edit')
    <a href="{{ route('documents.edit', $doc) }}">Редактировать</a>
@endcan

@cannot('app.documents.delete')
    <p>Доступ только для чтения.</p>
@endcannot

@canany(['app.documents.edit', 'app.documents.delete'])
    <div class="actions">...</div>
@endcanany
```

::: tip Проверка нескольких прав
Хелперов `hasAny`/`hasAll` нет — комбинируйте вызовы `hasPermission()` через
`&&` / `||`. Каждый вызов работает через short-circuit, поэтому ставьте
самую дешёвую или наиболее вероятную к провалу проверку первой.

```php
// ✅ У пользователя должны быть ОБА
if ($user->hasPermission(ReportsPermission::View) && $user->hasPermission(ReportsPermission::Export)) {
    return $this->buildReport();
}

// ✅ Хотя бы одно из
if ($user->hasPermission(DocumentsPermission::Edit) || $user->hasPermission(DocumentsPermission::Delete)) {
    // показать тулбар действий редактирования
}
```
:::

## Проверка того, что есть у пользователя

```php
// Все резолвленные ключи прав для панели (роли + прямые гранты объединены)
$user->permissions('app');            // Collection<int, string>

// Базовый PermissionSet (поддерживает wildcard-матчинг)
$user->permissionSet('app');          // PermissionSet

// Только прямые гранты (требует HasDirectGrants)
$user->grants('app');                 // Collection<DirectGrant>

// Проверка вхождения
$user->permissions('app')->contains('app.documents.view');
```

## Все методы проверки прав одним взглядом

| Метод | Возвращает | Бросает? | Gate? |
|---|---|---|---|
| `hasPermission($perm)` | `bool` | Нет | Нет |
| `checkPermission($perm)` | `bool` | Нет | Нет |
| `permissions($panelId)` | `Collection<int,string>` | Нет | Нет |
| `Gate::allows($key)` | `bool` | Нет | Да |
| `$this->authorize($key)` | `void` | Да (403) | Да |
| `Gate::authorize($key)` | `void` | Да (403) | Да |

## Назначение прав роли

В статических ролях права объявляются прямо в коде как **enum-кейсы** —
панель автоматически добавляет к каждому кейсу префикс (без `"app."` в
явном виде):

```php
use App\AzGuard\App\Permissions\DocumentsPermission;

public function permissions(): array
{
    return [
        DocumentsPermission::View,
        DocumentsPermission::Create,
        DocumentsPermission::Edit,
    ];
}
```

Каждый enum должен быть зарегистрирован на своей панели через
`->permissionEnums([...])`. После изменения классов ролей выполните
`php artisan guard:sync-roles`, чтобы отразить их в БД перед назначением.

Для ролей, хранящихся в БД, управляйте ключами прав командой
`guard:role-permissions`:

```bash
# Добавить ключ
php artisan guard:role-permissions add editor app.documents.view --panel=app

# Заменить весь список
php artisan guard:role-permissions sync editor --panel=app --keys=app.documents.view,app.documents.edit

# Удалить один
php artisan guard:role-permissions remove editor app.documents.create --panel=app

# Список
php artisan guard:role-permissions list editor --panel=app
```

## Права на фронтенде

Чтобы отдать резолвленные права ресурса на фронтенд, сгенерируйте
**Abilities DTO** для домена:

```bash
php artisan make:guard-abilities App Documents
```

Команда создаёт `{Domain}Abilities` (наследует `AzGuard\Abilities\AbilitiesDto`)
в директории `Abilities/` панели. DTO предоставляет булевы флаги
(`viewAny`, `view`, `create`, `update`, `delete`), сопоставленные с
резолвленными для панели ключами прав, проверенными через `Gate`.
Сериализуйте его в Inertia/JSON-пропсы через `toArray()`:

```php
$abilities = new DocumentsAbilities(/* ...резолвленные флаги... */);

return inertia('Documents/Index', [
    'can' => $abilities->toArray(),  // ['viewAny' => true, 'view' => true, ...]
]);
```

Интеграция с Inertia / Vue / React — [Права на фронтенде](/ru/basic-usage/abilities-frontend).

## Список всех прав

```bash
# Все права по всем панелям
php artisan guard:list-permissions

# Фильтр по панели (позиционный аргумент)
php artisan guard:list-permissions app
```

## Подводные камни

**Ключи прав привязаны к панели.** `documents.view` и `app.documents.view` —
одно и то же право, если enum зарегистрирован на панели `app`. Строковая
форма требует полный ключ с префиксом панели.

**Права с `#[RoleOnly]` исключены из Gate-поверхности.** Они предназначены
для назначения ролям и проверки через `$user->hasPermission(...)`;
`guard:doctor` не будет требовать для них метод политики, и они не попадают
в сгенерированные abilities.

**Не смешивайте строковые ключи и enum-кейсы бездумно.** Всегда используйте
enum-кейсы в PHP-коде (`DocumentsPermission::View`). Строковые ключи —
только там, где enum недоступен (конфиги, миграции, Artisan-команды).

## Лучшие практики

- **Один enum на ресурс.** `DocumentsPermission`, `UsersPermission`,
  `ReportsPermission` — а не один гигантский `AppPermission`.
- **CRUD как набор по умолчанию.** `view`, `create`, `edit`, `delete`.
  Добавляйте дополнительные по мере необходимости: `export`, `publish`, `approve`.
- **Помечайте только-внутренние кейсы `#[RoleOnly]`**, когда они должны
  назначаться ролям, но не попадать в сгенерированные abilities для фронтенда.
- **Никогда не хардкодьте строковые ключи в PHP.** Всегда ссылайтесь на
  enum-кейсы.
- **Держите имена кейсов enum описательными, но краткими.** `case Export` —
  нормально; `case ExportToCsvForExternalTeams` — нет.
