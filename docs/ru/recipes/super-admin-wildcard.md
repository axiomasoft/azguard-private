# Рецепт: Super-Admin Wildcard

Супер-администратор проходит все проверки Gate без исключения. AzGuard
реализует это через роль, чей метод `permissions()` возвращает wildcard-ключ.

## Определение роли

```php
namespace App\Guards\Admin\Roles;

use AzGuard\Permissions\PermissionKey;
use AzGuard\Roles\BaseRole;

class SuperAdminRole extends BaseRole
{
    public function getName(): string { return 'super-admin'; }
    public function getLevel(): int   { return 100; }

    public function permissions(): array
    {
        // Ссылайтесь на PermissionKey::WILDCARD вместо литерала '*'.
        return [PermissionKey::WILDCARD];  // Gate::before возвращает true для любой проверки
    }
}
```

Зарегистрируйте её на панели администратора через `roleClasses([SuperAdminRole::class])`
в провайдере панели.

## Проверка на супер-администратора

Спрашивайте пользователя напрямую через first-class метод `isSuperAdmin()` вместо того,
чтобы выводить это из `hasPermission('*')`:

```php
if ($user->isSuperAdmin()) {
    // wildcard на панели по умолчанию
}

if ($user->isSuperAdmin('admin')) {
    // wildcard на панели 'admin'
}
```

## Как это работает

Колбэк `Gate::before` в AzGuard резолвит `PermissionSet` пользователя для панели,
которой принадлежит проверяемая способность. Wildcard-набор (`[PermissionKey::WILDCARD]`)
соответствует любому ключу, поэтому проверка возвращает `true` до вызова метода политики.

```php
// Эквивалент того, что внутри делает хук Gate::before
if ($user->permissionSet('admin')->isWildcard()) {
    // разрешает любую способность на панели 'admin'
}
```

## Обход всех панелей через `Gate::before`

Если вы хотите, чтобы супер-администратор коротко замыкал *все* проверки Gate
(а не только способности, управляемые AzGuard), зарегистрируйте хук `Gate::before`.
Верните `true`, чтобы разрешить, или `null`, чтобы провалиться к обычным проверкам —
никогда `false`, что жёстко запретит:

```php
use AzGuard\Contracts\AzGuardUser;
use Illuminate\Support\Facades\Gate;

Gate::before(function ($user): ?bool {
    return $user instanceof AzGuardUser && $user->isSuperAdmin()
        ? true
        : null;   // провалиться дальше — пусть решают обычные политики
});
```

Проверка `instanceof AzGuardUser` делает хук безопасным для гостевых запросов
и не-AzGuard пользователей.

## Посегментные wildcards

Полный superadmin-wildcard `PermissionKey::WILDCARD` выше работает всегда.
Посегментные wildcards вида `'admin.*'` учитываются по умолчанию с иерархической
грамматикой: `*` соответствует ровно **одному** сегменту, разделённому точкой
(`admin.*` покрывает `admin.users`, но не `admin.users.delete`), `**` соответствует
рекурсивно (`admin.**` покрывает оба случая). Паттерны, не покрывающие ни одного
ключа каталога, отбрасываются.

Legacy-грамматика 0.2 (`*` пересекает точки) устарела и доступна ещё один цикл:

```php
// config/az-guard.php
'features' => [
    'wildcard_permission' => true,  // УСТАРЕЛО: восстановить legacy-грамматику 0.2
],
```

::: danger
Назначайте роль супер-администратора только инфраструктурным аккаунтам. Для
людей-администраторов предпочитайте явные права ролей, чтобы журнал аудита
оставался осмысленным.
:::
