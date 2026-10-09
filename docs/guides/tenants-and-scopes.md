# Tenants and scopes

AzGuard has two independent dimensions for "where":

- **Tenant** (`TenantRef`). The data boundary, such as an organization or a workspace. A panel either has no
  tenants or requires one. Grants of tenant A never apply in tenant B.
- **Assignment scope** (`AssignmentScopeRef`). A place inside a tenant, such as a team, project or store.
  Grants can be tenant-wide or bound to one scope.

## Assignment scopes

Declare which scope types the panel accepts and how tenant-wide grants apply in them:

```php
use AzGuard\Scopes\AssignmentScopePolicy;

->scopes(AssignmentScopePolicy::inherit(Team::class))   // a model class or an AssignmentScopeDefinition
```

| Policy | Check without a scope | Check in scope C |
|---|---|---|
| `inherit(...)` | Tenant-wide grants | Tenant-wide grants plus grants in C |
| `isolated(...)` | Tenant-wide grants | Grants in C only |
| `required(...)` | Denied (`context_required`) | Tenant-wide grants plus grants in C |
| `none()` (default) | Tenant-wide grants | Denied (`context_not_accepted`) |

A role must list the scopes it can be granted in:

```php
#[Role('editor')]
final class EditorRole extends BaseRole
{
    public function permissions(): array
    {
        return [PostPermission::View, PostPermission::Update];
    }

    public function scopes(): array
    {
        return [ModelAssignmentScopeDefinition::make(Team::class)];
    }
}
```

```php
$user->grantRole(EditorRole::class, on: $red);

$user->hasPermission(PostPermission::View, $red);    // true
$user->hasPermission(PostPermission::View, $blue);   // false
$user->hasPermission(PostPermission::View);          // false: the grant is not tenant-wide
$user->hasRole('editor', $red);                      // true
$user->roleNames($red);                              // ['editor']

AzGuard::withinScope($red, fn () => $user->hasPermission(PostPermission::View));   // true
```

- **Morph alias.** The scope model needs a morph alias, such as `team`. `AzGuard::withinScope()` needs a
  current or default panel.
- **Scope membership.** With `inherit`, a role granted tenant-wide applies in every team.
  `azguard:doctor` warns about this until you add `->requireMembership(TeamMembership::class)`, which
  implements `AssignmentScopeMembership`. It also requires the subject to be a member of the scope.
- **Custom scope types.** For a scope that is not a plain model, or that needs filters and labels, extend
  `BaseAssignmentScope` or implement `AssignmentScopeDefinition`.

### Resources inside scopes

When you check `on: $post`, the engine must know which scope the post belongs to. Map resource classes to a
`ResourceScopeResolver`:

```php
->resourceScopes([Post::class => PostScopeResolver::class])
```

```php
final class PostScopeResolver implements ResourceScopeResolver
{
    public function resolve(object $resource, ?AccessScope $selected = null): AccessScope
    {
        return $resource->team_id === null
            ? AccessScope::in(TenantRef::global())                    // a post outside any team
            : AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('team', $resource->team_id));
    }
}
```

In a panel with tenants, checking a resource without a resolver denies with `resource_scope_missing`. The
engine never guesses that an unknown object belongs to the current tenant.

## Tenants

```php
use AzGuard\Scopes\TenantPolicy;

->tenants(TenantPolicy::required(Team::class)->requireMembership(TeamMembership::class))
```

```php
final class TeamMembership implements TenantMembership
{
    public function isMember(SubjectRef $subject, TenantRef $tenant): bool
    {
        return DB::table('team_user')
            ->where('user_id', $subject->id())
            ->where('team_id', $tenant->id())
            ->exists();
    }
}
```

```php
$workspace = $user->guard('workspace');

$workspace->inTenant($a)->grantRole('member');           // changes need a tenant
$workspace->inTenant($a)->hasPermission(TaskPermission::View);   // true
$workspace->inTenant($b)->hasPermission(TaskPermission::View);   // false
$workspace->hasPermission(TaskPermission::View);                 // false: no tenant selected
```

- **Membership.** A required tenant requires active membership by default. Remove the user from the team,
  and the same check returns `false` even though the grant still exists.
- **Missing tenant.** A change without a tenant throws `TenantRequiredException`. A check without one denies
  with `tenant_required`. It never falls back to global grants.
- **Global roles.** `TenantPolicy::allowGlobalRoles([RootRole::class])` lets a role granted outside any
  tenant act inside each tenant. Other global grants never cross tenants.

## Resolving the tenant and scope of a request

`azguard.panel` asks the panel's resolvers, then the ones in `azguard.defaults.tenants.resolvers` and
`azguard.defaults.scopes.resolvers`:

```php
->tenantResolvers([TeamFromRoute::class])     // implements TenantResolver: resolve(Request): ?TenantRef
->scopeResolvers([ProjectFromHeader::class])  // implements AssignmentScopeResolver
```

A tenant taken from the URL or a header is only a candidate. Admission still checks membership. The Filament
plugin provides `FilamentTenantResolver` for Filament's tenant.

## Queues

Jobs do not inherit the tenant or scope. Pass the tenant to the job and use `inTenant()` or `on:` there.
