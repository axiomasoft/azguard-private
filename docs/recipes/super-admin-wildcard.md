# Recipe: Super-Admin Wildcard

A super-admin bypasses all Gate checks. AzGuard implements this via a role whose `permissions()` returns the wildcard key.

## Define the role

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
        // Reference PermissionKey::WILDCARD instead of the literal '*'.
        return [PermissionKey::WILDCARD];  // Gate::before returns true for all abilities
    }
}
```

Register it on the admin panel via `roleClasses([SuperAdminRole::class])` in your panel provider.

## Checking for a super-admin

Ask the user directly with the first-class `isSuperAdmin()` method instead of inferring it from `hasPermission('*')`:

```php
if ($user->isSuperAdmin()) {
    // wildcard on the default panel
}

if ($user->isSuperAdmin('admin')) {
    // wildcard on the 'admin' panel
}
```

## How it works

AzGuard's `Gate::before` callback resolves the user's `PermissionSet` for the panel the ability belongs to. A wildcard set (`[PermissionKey::WILDCARD]`) matches every key, so the check returns `true` before the policy method is called.

```php
// Equivalent to what the Gate::before hook does internally
if ($user->permissionSet('admin')->isWildcard()) {
    // grants every ability on the 'admin' panel
}
```

## Bypassing every panel via `Gate::before`

If you want a super-admin to short-circuit *all* Gate checks (not just AzGuard-managed abilities), register a `Gate::before` hook. Return `true` to allow, or `null` to fall through to the normal checks — never `false`, which would hard-deny:

```php
use AzGuard\Contracts\AzGuardUser;
use Illuminate\Support\Facades\Gate;

Gate::before(function ($user): ?bool {
    return $user instanceof AzGuardUser && $user->isSuperAdmin()
        ? true
        : null;   // fall through — let normal policies decide
});
```

The `instanceof AzGuardUser` guard keeps the hook null-safe for guest requests and non-AzGuard authenticatables.

## Segment wildcards

The full `PermissionKey::WILDCARD` superadmin wildcard above always works. Segment
wildcards like `'admin.*'` are honoured by default with the hierarchical grammar:
`*` matches exactly **one** dotted segment (`admin.*` covers `admin.users` but not
`admin.users.delete`), `**` matches recursively (`admin.**` covers both). Patterns
that cover no catalog key are dropped.

The legacy 0.2 grammar (`*` crosses dots) is deprecated and available for one more
cycle:

```php
// config/az-guard.php
'features' => [
    'wildcard_permission' => true,  // DEPRECATED: restore the legacy 0.2 grammar
],
```

::: danger
Assign the super-admin role only to infrastructure accounts. For human admins, prefer explicit role permissions so your audit log is meaningful.
:::
