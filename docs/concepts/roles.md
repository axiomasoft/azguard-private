# Roles

A role is a PHP class that extends `BaseRole` and lists permissions. The database stores only **who holds the
role**, under its key.

```php
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('editor', label: 'Editor', level: 10)]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [
            PostPermission::View,
            PostPermission::Update,
            'comments.*',              // local names and patterns work too
        ];
    }
}
```

- **Discovery.** Roles in the panel's `Roles/` directory are discovered. Add others with
  `->roles([...])`. Generate one with `php artisan azguard:make:role Admin Editor`.
- **The key** (`editor`) is the stored identity. It must be unique in the panel and should never change. If you
  must rename it, see [Renaming a key](#renaming-a-key).
- **`label`** may be a translation key. **`level`** only orders roles for display. Checks never read it.
- **Methods.** Every attribute has an equivalent method (`key()`, `label()`, `superAdmin()`, `grantable()`,
  `formerKeys()`, `level()`). Override the method when the value must be computed.

## Granting

```php
$user->grantRole(EditorRole::class);                       // by class
$user->grantRole('editor');                                // by key
$user->grantRole(['editor', 'author']);                    // several
$user->grantRole('editor', until: now()->addWeek());       // expiring
$user->revokeRole('editor');
$user->syncRoles(['editor']);                              // exactly these, in this scope
```

See [Granting access](/guides/granting-access) for actors, scopes, fields, results and the CLI.

## Super admin

A role marked `#[SuperAdmin]` holds every `#[RequiresGrant]` permission of the panel. The package ships one:

```php
->roles([\AzGuard\Roles\SuperAdminRole::class])   // key "superadmin"
```

```php
$user->grantRole('superadmin');
$user->isSuperAdmin();   // true
```

A super admin is **not** a bypass:

- Policies still veto. In the quick start, a super admin still cannot update another user's post.
- Restrictions still deny, for example a locked account.
- `#[PolicyOnly]` permissions are still decided by their policy alone.
- The role is scoped like any other. Granted in team A, it covers team A only.

To cross tenants on purpose, see `TenantPolicy::allowGlobalRoles()` in
[Tenants and scopes](/guides/tenants-and-scopes).

## Roles granted automatically

A role that implements `GrantedAutomatically` is held by every subject its rule accepts. Nothing is stored for
it. `#[NotGrantable]` forbids granting it by hand, which throws `RoleNotGrantableException`.

```php
#[Role('author', label: 'Author')]
#[NotGrantable]
final class AuthorRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [PostPermission::Create];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return $subject->getAttribute('email_verified_at') !== null;
    }
}
```

The rule runs on every check and is never cached across requests. Keep it cheap, or read data the model has
already loaded. Automatic roles also admit users to `azguard.panel` routes.

## Scoped roles

By default, a role is tenant-wide. List the assignment scopes it may be granted in. Use `scopeRequired()` to
forbid tenant-wide grants of the role.

```php
public function scopes(): array
{
    return [ModelAssignmentScopeDefinition::make(Team::class)];
}

public function scopeRequired(): bool
{
    return true;
}
```

Granting a role in a scope it does not list throws `AssignmentScopeNotAcceptedException`. See
[Tenants and scopes](/guides/tenants-and-scopes).

## Renaming a key

Keep the old key in `#[FormerKeys]`, deploy, and move the stored grants:

```php
#[Role('content-editor')]
#[FormerKeys('editor')]
final class EditorRole extends BaseRole { /* ... */ }
```

```bash
php artisan azguard:roles:rename-key editor content-editor --dry-run
php artisan azguard:roles:rename-key editor content-editor --force   # --force is required in production
```

A former key is not an alias. Grants under the old key give nothing until they are moved.
`azguard:doctor` reports grants that still use a former key.

## Listing

```bash
php artisan azguard:roles:list --panel=admin   # key, class, granted by, super admin, scopes, holders
```
