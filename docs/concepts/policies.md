# Policies

A policy is a plain class whose methods carry `#[Decides(Permission::Case)]`. It gets the subject and the
resource of the check (`on:`). It can return `bool`, `null` or an `Illuminate\Auth\Access\Response`.

```php
use AzGuard\Policies\Decides;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

final class PostPolicy
{
    #[Decides(PostPermission::Update)]
    public function update(Model $user, mixed $resource = null): bool
    {
        return $resource === null || $resource->user_id === $user->getKey();
    }

    #[Decides(PostPermission::Delete)]
    public function delete(Model $user, mixed $resource = null): Response
    {
        return $resource?->published_at === null
            ? Response::allow()
            : Response::deny('Published posts cannot be deleted.', 'post_published');
    }
}
```

## What the answer means

The meaning depends on the [authority mode](/concepts/permissions#who-decides-the-authority-mode) of the
permission:

| Permission | Grant held | Policy returns | Result |
|---|---|---|---|
| `#[RequiresGrant]` | no | (not called) | deny `not_granted` |
| `#[RequiresGrant]` | yes | `true`, `null`, allow `Response`, or no policy | allow |
| `#[RequiresGrant]` | yes | `false`, deny `Response` | deny `policy` (veto) |
| `#[PolicyOnly]` | (not read) | `true`, allow `Response` | allow |
| `#[PolicyOnly]` | (not read) | `false`, `null`, deny `Response` | deny `policy` |

- **A policy never replaces a grant.** Super admins and hooks cannot skip a veto.
- **Deny details.** A deny `Response` keeps its message and code, and `Gate::inspect()` and
  `SubjectAccess::decide()` return them. The `azguard.can` middleware answers with the status and message of
  `#[CheckPermission]`, so it never leaks policy details.
- **A throwing policy** denies access with the reason `policy_error`.

## Binding a policy to permissions

1. **By folder (default).** `Policies/Posts/PostPolicy.php` decides `Permissions/Posts/PostPermission.php`.
   Each `#[Decides]` method binds one case. The method name is free.
2. **`#[PolicyFor(PostPermission::class)]`** on the class, when the folders do not pair them.
3. **`PolicyBinding::for()`** in the panel provider, for a policy outside the panel directory:

   ```php
   ->policies([PolicyBinding::for(PostPermission::Update, PostPolicy::class, 'update')])
   ```

4. **`PolicyBinding::gate()`**, or `GateSource::make()->map()` in `permissions([...])`, maps a permission to
   an existing Laravel Gate ability, such as a feature flag:

   ```php
   ->policies([PolicyBinding::gate(ReportPermission::Export, 'export-enabled')])
   ```

A `#[PolicyOnly]` permission without a binding, or one permission bound twice, fails at boot with
`InvalidPolicyStructureException` or `DuplicatePolicyBindingException`.

Generate a policy with `php artisan azguard:make:policy Admin Posts`, or together with the enum using
`azguard:make:permission ... --policy`.

## Parameters

The first parameter receives the subject and the second receives the `on:` value: a model, an
`AssignmentScopeRef` or `null`. Other parameters are resolved from the container by type. Keep policies free of
request state, because they run for every check, in queues and in the CLI.

## Laravel policies

Policies registered with `Gate::policy()` keep working for abilities AzGuard does not own. For abilities it owns,
AzGuard answers authoritatively (see [Checking access → Gate](/guides/checking-access#laravel-gate)). Move the
logic into a `#[Decides]` method, or bind it with `PolicyBinding::gate()`.
