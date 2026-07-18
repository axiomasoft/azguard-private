# AzGuard Core

Code-first role-based access control and permissions for Laravel. Permissions
are declared in PHP (enums or policy attributes), resolved through pluggable
**grant sources** (class roles, database roles, direct grants), and scoped to
**panels** (e.g. `app`, `admin`).

Built on Laravel's own Gate, policies, and middleware — no parallel concepts to
learn.

## Install

```bash
composer require axioma-studio/azguard-core
php artisan vendor:publish --tag=az-guard-config
php artisan migrate
```

## Usage

Add the trait to your user model and declare the public contract so you (and your
services) have a real type to hint — the trait provides every method:

```php
use AzGuard\Contracts\AzGuardUser;
use AzGuard\Concerns\HasAzGuard;

class User extends Authenticatable implements AzGuardUser
{
    use HasAzGuard;
}
```

Add `HasScopedRoles` / `HasDirectGrants` (contract + matching trait) when you need
entity-scoped roles or direct grants. The contract and trait share a short name
(as with Laravel's own `Authorizable`), so alias the contract:

```php
use AzGuard\Contracts\HasDirectGrants as HasDirectGrantsContract;
use AzGuard\Concerns\HasDirectGrants;

class User extends Authenticatable implements AzGuardUser, HasDirectGrantsContract
{
    use HasAzGuard;
    use HasDirectGrants;
}
```

Assign roles and check permissions with bare, predictable names:

```php
$user->assignRole('editor');
$user->hasRole('editor');                  // true

$user->hasPermission('app.posts.edit');    // via roles / grants
$user->permissions('app');                 // Collection<string>
$user->flushPermissions();                 // clear the resolved cache
```

It also works through Laravel's Gate and Blade:

```php
Gate::allows('app.posts.edit');

@azcan('app.posts.edit')
    <x-edit-button />
@endazcan
```

### Direct grants

One-off, optionally expiring permissions that bypass roles — one verb set
everywhere (`grant` / `revoke` / `grants`):

```php
use AzGuard\Facades\AzGuard;

AzGuard::forUser($user)->on('app')->ttl(3600)->grant('app.posts.export');
AzGuard::forUser($user)->on('app')->revoke('app.posts.export');
AzGuard::forUser($user)->on('app')->grants();   // active grants
```

### Super-admin

Check it first-class instead of hardcoding the wildcard, and wire absolute-allow
once via `Gate::before`:

```php
use AzGuard\Contracts\AzGuardUser;
use Illuminate\Support\Facades\Gate;

$user->isSuperAdmin();            // true when the user holds the '*' wildcard

// AppServiceProvider::boot()
Gate::before(fn ($user, string $ability) =>
    $user instanceof AzGuardUser && $user->isSuperAdmin() ? true : null);
```

The wildcard value lives in one place — `AzGuard\Permissions\PermissionKey::WILDCARD` — so
reference it rather than a literal `'*'`.

### Testing

Ship-free helpers under `AzGuard\Testing` let you test authorization without
panels, migrations or a catalog:

```php
use AzGuard\Testing\FakeAzGuardUser;
use AzGuard\Testing\FakeGrantSource;

// A database-free user double for adapter/unit tests:
$user = (new FakeAzGuardUser)->grant('app', DocumentsPermission::View);
$user->hasPermission(DocumentsPermission::View);   // true
(new FakeAzGuardUser)->wildcard()->isSuperAdmin();  // true

// Or grant a fixed set to every real user via a fake source:
app()->instance(FakeGrantSource::class, (new FakeGrantSource)->grant('app', DocumentsPermission::View));
AzGuard::registerGrantSource(FakeGrantSource::class);
```

Context checks (`hasPermissionIn`) require the optional `azguard/context` package;
`$user->hasContextGuard()` reports whether it is installed (a call without it
returns `false` and logs a one-time debug warning).

## Key concepts

- **Panel** — an authorization scope. Set per request via the `SetCurrentPanel`
  middleware or `azguard.panel_check`.
- **GrantSource** — a pluggable resolver (`ClassRoleGrantSource`,
  `DatabaseRoleGrantSource`, `DirectGrantSource`). The resolver merges them all.
- **PermissionCatalog** — the registry of declared permissions, built from
  enums or `#[GateAbility]` policy attributes.

See the [documentation](https://github.com/axioma-studio/azguard) for panels,
the permission catalog, scoped roles, and the Artisan tooling.

## License

MIT.
