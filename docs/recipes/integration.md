# Recipe: Integration & Testing

How to wire AzGuard into your app's types, test it without a database, and reason about the optional context guard.

## The public actor contract

Declare `AzGuardUser` on your User model and add the trait — the trait already implements every method the contract requires:

```php
use AzGuard\Contracts\AzGuardUser;
use AzGuard\Concerns\HasAzGuard;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements AzGuardUser
{
    use HasAzGuard;
}
```

Type-hint `AzGuardUser` (an interface) in your services and actions rather than the concrete `App\Models\User` — traits cannot be type-hinted, and the contract keeps your access layer decoupled from the Eloquent model:

```php
use AzGuard\Contracts\AzGuardUser;

final class PublishArticle
{
    public function __invoke(AzGuardUser $user, Article $article): void
    {
        // $user->hasPermission(...), $user->isSuperAdmin(), etc. are all type-safe
    }
}
```

### Segregated contracts

The composite `AzGuardUser` bundles the permission/role surface (`HasPermissions`, `HasRoles`). Two opt-in concerns — entity-scoped roles (`HasScopedRoles`) and direct grants (`HasDirectGrants`) — each ship as a contract **plus** a matching trait. Declare only what you use:

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

The contract and trait share a short name (`HasDirectGrants`), so alias the contract on import — exactly as Laravel does for its own `Authorizable`. The same pattern applies to the `HasScopedRoles` contract/trait pair.

## Testing without a database

For unit tests that only touch the permission surface, use `FakeAzGuardUser` — a dependency-free double with an in-memory permission set. No migrations, panels or catalog required:

```php
use AzGuard\Testing\FakeAzGuardUser;
use App\Guards\App\Documents\Permissions\DocumentsPermission;

$user = (new FakeAzGuardUser)->grant('app', DocumentsPermission::View);

$user->hasPermission(DocumentsPermission::View); // true
$user->isSuperAdmin();                           // false

(new FakeAzGuardUser)->wildcard()->isSuperAdmin(); // true
```

Type-hint `HasPermissions` (or `Authenticatable`) where you accept the fake in the adapter under test. It intentionally provides no roles/relations — use a real Eloquent user with `HasAzGuard` when you need role behavior.

To grant a fixed set to **real** users without touching roles or DB rows, register a `FakeGrantSource`. It sits above the built-in sources, so its grants win during tests:

```php
use AzGuard\Facades\AzGuard;
use AzGuard\Testing\FakeGrantSource;

$fake = (new FakeGrantSource)->grant('app', DocumentsPermission::View);
app()->instance(FakeGrantSource::class, $fake);

AzGuard::registerGrantSource(FakeGrantSource::class);

// now any user passes:
$user->hasPermission(DocumentsPermission::View); // true

// (new FakeGrantSource)->wildcard() grants everything, like a super-admin
```

## Context guard visibility

Contextual (per-entity) checks live in the optional `azguard/context` package. Probe whether it is installed before relying on `hasPermissionIn()`:

```php
use AzGuard\Facades\AzGuard;

$user->hasContextGuard();   // per-user check
AzGuard::hasContextGuard(); // container-level check
```

When the context guard is **not** bound, `hasPermissionIn()` returns `false` and logs a one-time debug warning (rather than throwing), so a missing optional package degrades gracefully instead of breaking every request.

## Headless / panel-less checks

A plain string key check works without registering a panel — the catalog filter is lenient for unregistered panels, so you don't need a panel provider just to assert a permission:

```php
$user->hasPermission('app.documents.view'); // works with no panel registered
```

For a catalog-free setup in tests, pair this with `FakeGrantSource` (above): grant the exact keys you need and skip panel/catalog wiring entirely.
