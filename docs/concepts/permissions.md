# Permissions

A permission is a case of a **string-backed enum** in the panel's `Permissions/` directory. The case value is
the local name (`posts.update`). The panel adds its prefix (`admin.posts.update`).

```php
use AzGuard\Permissions\Describe;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;

#[Resource(label: 'Posts', model: Post::class)]
#[RequiresGrant]
enum PostPermission: string
{
    #[Describe('View list')]
    case ViewAny = 'posts.view_any';

    #[Describe('Update', group: 'Editing', description: 'Change any field of a post')]
    case Update = 'posts.update';
}
```

Generate one with `php artisan azguard:make:permission Admin Posts`. These options are available:

- `--model=` sets the model of `#[Resource]`;
- `--case=Name=local.key` (repeatable) replaces the five CRUD cases;
- `--policy` and `--abilities` also generate the policy and the abilities class;
- `--authority=policy` makes the permissions policy-only.

A local name has at least two dot-separated segments of `[a-z0-9_-]`, for example `posts.update` or
`reports.sales.export`.

## Who decides: the authority mode

Every enum or case must carry exactly one of two attributes. A case-level attribute overrides the enum-level
one.

| Attribute | Allowed when | Policy role |
|---|---|---|
| `#[RequiresGrant]` | The subject holds a qualifying grant (direct, through a role, or as super admin) | A bound policy may **veto** |
| `#[PolicyOnly]` | The bound policy returns `true` | The policy alone decides; grants are never read, and the case cannot be granted |

```php
#[PolicyOnly]
enum ProfilePermission: string
{
    case Edit = 'profile.edit';    // decided only by ProfilePolicy::edit()
}
```

A `#[PolicyOnly]` case without a policy binding is a definition error at boot. See [Policies](/concepts/policies).

## Granted to everyone

`#[GrantedToAll]` on a case of a `#[RequiresGrant]` enum gives the permission to **every accepted subject of
the panel**, in a valid scope. It does not give it to guests or to other tenants. Bound policies and
restrictions still apply.

```php
#[RequiresGrant]
enum ProfilePermission: string
{
    #[GrantedToAll]
    case View = 'profile.view';
}
```

## Metadata

- `#[Resource(label:, model:)]` on the enum gives the group a label and a domain model. The model lets
  `$user->can('update', $post)` find the permission: `update` plus `Post` gives `PostPermission::Update` (see
  [Checking access](/guides/checking-access#laravel-gate)). It does not create permissions.
- `#[Describe(label, group:, description:)]` on a case is used by `azguard:catalog:list`, the Filament editors
  and `PanelAccess::schema()`.

## Patterns

Grants and roles may use patterns:

- `posts.*` matches exactly one segment (`posts.view`, `posts.update`);
- `posts.**` matches one or more segments.

```php
$user->grantPermission('posts.*');
```

A pattern needs at least two segments: `posts.*` is valid, and `*` or `**` alone are invalid. Patterns expand
only over `#[RequiresGrant]` permissions.

## Dynamic permissions

Permissions created at runtime are opt-in per panel:

```php
->permissions([DatabaseSource::make()->dynamicPermissions()])
```

```php
AzGuard::panel('admin')->permissions()->create('reports.quarterly', label: 'Quarterly report');
$user->hasPermission('reports.quarterly', guard: 'admin');
```

The CLI has the same operations: `azguard:permissions:create` and `azguard:permissions:delete`. Deleting a
permission also revokes its grants in one transaction. Dynamic permissions are always `RequiresGrant`.

## Listing

```bash
php artisan azguard:catalog:list --panel=admin   # every permission of the panel with its authority and labels
```
