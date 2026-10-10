# Sources

A panel reads permission definitions and grants from **sources**. A panel always has these:

- the enums of its `Permissions` folder;
- its `Roles` folder;
- its `Policies` folder (`FolderSource`, implicit).

Everything else is listed in `permissions([...])`. Run `php artisan azguard:panels:list` to see a panel's
sources.

## Built-in sources

| Source | What it provides |
|---|---|
| `DatabaseSource::make()` | Stored role and permission grants: the API, the CLI and the Filament editors |
| `->dynamicPermissions()` | Also permissions created at runtime (`AzGuard::panel('admin')->permissions()->create()`) |
| `->rolesOnly()` | Role grants only; direct permission grants are rejected |
| `->models(roleGrant:, permissionGrant:, permission:)` | Your Eloquent subclasses with custom fields (`azguard:make:models`) |
| `->storage('reporting')` | Another configured storage |
| `RelationSource::make(Team::class, 'members', 'pivot.role')` | Roles from an existing membership relation: a user who is a member of a team with pivot `role = editor` holds `editor` in that team |
| `GateSource::make()->map(Permission, 'ability', Model::class)` | A native Laravel ability decides an AzGuard permission (a policy-only bridge) |
| `FilamentSource::make('admin')` | Permissions read from Filament resources, pages and widgets (`azguard-filament`) |
| `FakeSource` | Tests: grants in memory (`AzGuard\Testing\FakeSource`) |

```php
->scopes(AssignmentScopePolicy::inherit(Team::class))
->permissions([
    DatabaseSource::make()->dynamicPermissions(),
    RelationSource::make(Team::class, 'members', 'pivot.role'),
])
```

`RelationSource` needs the related model to be an assignment scope of the panel. The role name in the pivot
must be a role key of the panel. `azguard:explain` shows such grants with the source `relation:team`.

## A custom source

A source is a class that implements `AzGuard\Contracts\Sources\Source` together with the capabilities it
provides:

| Interface | Provides |
|---|---|
| `ProvidesPermissions` | Permission definitions. `isDynamic()` is `false` for definitions known at boot; they go to `azguard:catalog:cache` |
| `ProvidesGrants` | Direct grants of a subject: `grants(SubjectRef, array $scopes, EvaluationContext): iterable<Grant>` |
| `ProvidesRoleGrants` | Role grants of a subject: `iterable<RoleContribution>` |
| `ProvidesPolicies` | Policy bindings |
| `ChecksHealth` | `azguard:doctor` checks |

`volatility()` tells AzGuard how long it may reuse what the source read:

| `Volatility` | Reuse |
|---|---|
| `Stable` | Until the panel state changes |
| `Request` | For the rest of the request or job |
| `Volatile` | Read again on every check |

`php artisan azguard:make:source Ldap --panel=admin` creates a skeleton. This example was tested against 0.7.0:

```php
namespace App\Guards\Admin\Sources;

use AzGuard\Attributes\AsSource;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;

#[AsSource('ldap')]
final class LdapSource implements ProvidesGrants
{
    /** @param array{readers?: list<string>} $config azguard.sources.ldap */
    public function __construct(private array $config = []) {}

    public function id(): string
    {
        return 'ldap';
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if (! in_array($subject->id(), $this->config['readers'] ?? [], true)) {
            return;
        }

        foreach ($scopes as $scope) {
            yield Grant::of(PermissionPattern::of($context->panel()->id(), 'posts.view'), $this->id(), $scope);
        }
    }

    public function volatility(): Volatility
    {
        return Volatility::Request;
    }
}
```

```php
// config/azguard.php
'sources' => ['ldap' => ['readers' => ['5']]],

// the panel provider: name the source
->permissions([DatabaseSource::make(), 'ldap'])
```

### Registering a source

- **`#[AsSource('name')]`.** The class is registered when it lives in the panel's `Sources` folder or in
  `Shared/Sources`. Its constructor receives the configuration as `$config`.
- **`AzGuard::sources()->extend('name', fn (Application $app, array $config) => new LdapSource($config))`.**
  Call it in a service provider.
- **An instance.** Pass it directly: `->permissions([new LdapSource([...])])`.

Each panel gets its own instance. `azguard:sources:list` shows the registered names and the panels that
use them.

A source must not throw for a normal "no grants" answer. An exception from a source **denies** the check with
the reason `source_error` and is logged. The decision fails closed.

Package authors can check a source with the contract test traits in `AzGuard\Testing\Contracts`.

```php
use AzGuard\Testing\Contracts\SourceContractTests;

final class LdapSourceTest extends TestCase
{
    use SourceContractTests;

    protected function azguardSource(): Source
    {
        return new LdapSource(['readers' => ['1']]);
    }
}
```
