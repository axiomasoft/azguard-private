# Супер-admin Wildcard

Супер-администратор должен проходить **все** проверки прав без явного перечисления.

## Проверка на супер-администратора

Спрашивайте пользователя напрямую через first-class метод `isSuperAdmin()` вместо того, чтобы выводить это из `hasPermission('*')`:

```php
if ($user->isSuperAdmin()) {
    // wildcard на панели по умолчанию
}

if ($user->isSuperAdmin('admin')) {
    // wildcard на панели 'admin'
}
```

## Через Gate::before()

Верните `true`, чтобы разрешить, или `null`, чтобы провалиться к обычным проверкам — никогда `false`, что жёстко запретит:

```php
// app/Providers/AppServiceProvider.php
use AzGuard\Contracts\AzGuardUser;
use Illuminate\Support\Facades\Gate;

Gate::before(function ($user): ?bool {
    return $user instanceof AzGuardUser && $user->isSuperAdmin()
        ? true
        : null;   // провалиться дальше — пусть решают обычные политики
});
```

Проверка `instanceof AzGuardUser` делает хук безопасным для гостевых запросов и не-AzGuard пользователей.

## Через роль с wildcard

```php
use AzGuard\Permissions\PermissionKey;
use AzGuard\Roles\BaseRole;

class SuperAdminRole extends BaseRole
{
    public function permissions(): array
    {
        // Ссылайтесь на PermissionKey::WILDCARD вместо литерала '*'.
        return [PermissionKey::WILDCARD]; // AzGuard возвращает true для любой проверки
    }
}

// Назначение (по имени роли — 'super-admin')
$user->assignRole('super-admin');

// Теперь любая проверка возвращает true
$user->hasPermission(PostsPermission::Delete); // true
$user->hasPermission(AdminPermission::Nuke);   // true
```

## Посегментные wildcards

Полный superadmin-wildcard `PermissionKey::WILDCARD` выше работает всегда.
Посегментные wildcards вида `'admin.*'` учитываются по умолчанию с иерархической
грамматикой: `*` соответствует ровно **одному** сегменту (`admin.*` покрывает
`admin.users`, но не `admin.users.delete`), `**` — рекурсивно (`admin.**`
покрывает оба). Паттерны, не покрывающие ни одного ключа каталога, отбрасываются.

Legacy-грамматика 0.2 (`*` пересекает точки) устарела и доступна ещё один цикл:

```php
// config/az-guard.php
'features' => [
    'wildcard_permission' => true,  // УСТАРЕЛО: вернуть legacy-грамматику 0.2
],
```

::: danger
Назначайте роль супер-администратора только через сидеры или CLI. Никогда не давайте UI для самоназначения.
:::
