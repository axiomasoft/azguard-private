# Интеграция и тесты

Как встроить AzGuard в типы приложения, тестировать его без базы данных и рассуждать об опциональном context guard.

## Публичный контракт актора

Объявите `AzGuardUser` на модели User и подключите трейт — трейт уже реализует каждый метод, который требует контракт:

```php
use AzGuard\Contracts\AzGuardUser;
use AzGuard\Concerns\HasAzGuard;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements AzGuardUser
{
    use HasAzGuard;
}
```

Тайп-хинтите `AzGuardUser` (интерфейс) в сервисах и экшенах вместо конкретного `App\Models\User` — трейты нельзя тайп-хинтить, а контракт держит слой доступа отвязанным от Eloquent-модели:

```php
use AzGuard\Contracts\AzGuardUser;

final class PublishArticle
{
    public function __invoke(AzGuardUser $user, Article $article): void
    {
        // $user->hasPermission(...), $user->isSuperAdmin() и т.д. — всё типобезопасно
    }
}
```

### Сегрегированные контракты

Композитный `AzGuardUser` собирает права/роли (`HasPermissions`, `HasRoles`). Два опциональных концерна — entity-scoped роли (`HasScopedRoles`) и прямые гранты (`HasDirectGrants`) — поставляются как контракт **плюс** одноимённый трейт. Объявляйте только то, что используете:

```php
use AzGuard\Contracts\AzGuardUser;
use AzGuard\Contracts\HasDirectGrants as HasDirectGrantsContract;
use AzGuard\Concerns\HasAzGuard;
use AzGuard\Concerns\HasDirectGrants;

class User extends Authenticatable implements AzGuardUser, HasDirectGrantsContract
{
    use HasAzGuard;
    use HasDirectGrants;
}
```

Контракт и трейт делят короткое имя (`HasDirectGrants`), поэтому алиасьте контракт при импорте — ровно так, как Laravel делает для своего `Authorizable`. Тот же паттерн применяется к паре контракт/трейт `HasScopedRoles`.

## Тестирование без базы данных

Для юнит-тестов, которые трогают только права, используйте `FakeAzGuardUser` — независимый от зависимостей дубль с in-memory набором прав. Ни миграций, ни панелей, ни каталога не нужно:

```php
use AzGuard\Testing\FakeAzGuardUser;
use App\Guards\App\Documents\Permissions\DocumentsPermission;

$user = (new FakeAzGuardUser)->grant('app', DocumentsPermission::View);

$user->hasPermission(DocumentsPermission::View); // true
$user->isSuperAdmin();                           // false

(new FakeAzGuardUser)->wildcard()->isSuperAdmin(); // true
```

Тайп-хинтите `HasPermissions` (или `Authenticatable`) там, где принимаете фейк в тестируемом адаптере. Он намеренно не даёт ролей/связей — используйте реального Eloquent-пользователя с `HasAzGuard`, когда нужно поведение ролей.

Чтобы выдать фиксированный набор **реальным** пользователям без ролей и строк в БД, зарегистрируйте `FakeGrantSource`. Он стоит выше встроенных источников, так что его гранты выигрывают в тестах:

```php
use AzGuard\Facades\AzGuard;
use AzGuard\Testing\FakeGrantSource;

$fake = (new FakeGrantSource)->grant('app', DocumentsPermission::View);
app()->instance(FakeGrantSource::class, $fake);

AzGuard::registerGrantSource(FakeGrantSource::class);

// теперь любой пользователь проходит:
$user->hasPermission(DocumentsPermission::View); // true

// (new FakeGrantSource)->wildcard() выдаёт всё, как супер-администратору
```

## Видимость context guard

Контекстные (по-сущностные) проверки живут в опциональном пакете `azguard/context`. Прощупайте, установлен ли он, прежде чем полагаться на `hasPermissionIn()`:

```php
use AzGuard\Facades\AzGuard;

$user->hasContextGuard();   // проверка на уровне пользователя
AzGuard::hasContextGuard(); // проверка на уровне контейнера
```

Когда context guard **не** привязан, `hasPermissionIn()` возвращает `false` и один раз логирует debug-предупреждение (вместо исключения) — так отсутствие опционального пакета деградирует мягко, а не ломает каждый запрос.

## Headless / проверки без панели

Проверка по обычному строковому ключу работает без регистрации панели — фильтр каталога снисходителен к незарегистрированным панелям, поэтому провайдер панели не нужен только ради проверки права:

```php
$user->hasPermission('app.documents.view'); // работает без зарегистрированной панели
```

Для setup без каталога в тестах сочетайте это с `FakeGrantSource` (выше): выдайте нужные ключи и полностью пропустите настройку панели/каталога.
