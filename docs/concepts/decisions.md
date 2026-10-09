# How a decision is made

Every entry point builds the same request and runs the same pipeline:

- the trait (`hasPermission`), `SubjectAccess`, the facade (`check`, `authorize`);
- the `azguard.can` middleware, Laravel's Gate, the Filament plugin and the CLI.

The request contains the subject, the permission, the panel, the tenant, the assignment scope and the resource.

## The pipeline

1. **Prepare.** Resolve the panel (see [Which panel?](/concepts/panels#which-panel)), the subject and the
   permission definition. An unknown permission or panel is a configuration error.
2. **Boundary.** Resolve the tenant and the assignment scope from the explicit input, the resource or the
   current request. A required tenant that is missing, or a resource of another tenant, denies here.
3. **Before hooks.** They can deny or continue. They can never allow.
4. **Authority.**
   - **`#[PolicyOnly]`:** call the bound policy. Grants are not read.
   - **`#[RequiresGrant]`:** collect grants from every source of the panel: direct grants, role grants,
     automatic roles, relations and super admin roles. Without a qualifying grant, deny `not_granted`. With
     one, call the bound policy, which may veto.
5. **Restrictions.** Mandatory denials, such as a locked account or a licence. They apply to super admins too.
6. **State.** Confirm that the grants read belong to one consistent version of the panel state.
7. **After hooks.** They observe only. Their errors are logged and do not change the answer.

`azguard:explain` prints each stage:

```bash
php artisan azguard:explain user:1 posts.update --context=team:3
```

## Decision reasons

`SubjectAccess::decide()` returns a `Decision` with:

- `effect`: `allow` or `deny`;
- `reason`: a `DecisionReason`;
- `message`, `status` and `code`, from a policy `Response`.

```php
$decision = $user->guard('admin')->decide(PostPermission::Update, $post);
$decision->allowed();        // bool
$decision->reason;           // DecisionReason::Policy
$decision->message;          // 'Only your own posts.'
```

| Reason | Meaning |
|---|---|
| `granted`, `super_admin`, `policy` (allow) | Allowed |
| `not_granted` | No qualifying grant |
| `policy` (deny) | Vetoed or refused by the policy |
| `restricted` | A restriction denied |
| `tenant_required`, `tenant_mismatch`, `context_required`, `context_not_accepted`, `context_mismatch`, `context_ineligible`, `resource_scope_missing` | Tenant or scope boundary |
| `source_error`, `policy_error`, `restriction_error`, `hook_error`, `condition_error`, `context_filter_error` | A component failed (fail closed) |
| `consistency_error` | The grants could not be read at one consistent version |

## Fail closed

A component that throws or answers inconsistently **denies** the check. It never falls back to "allowed":

- sources, policies, hooks, restrictions, conditions and scope filters fail with one of the `*_error` reasons;
- every such failure is logged as a warning, `AzGuard evaluation failed.`, with the component, the reason, the
  exception class and, for package exceptions, the stable `code`;
- exception messages are not logged, because they may contain data.

Configuration errors behave differently depending on the caller:

- **Direct API.** `hasPermission()` and `check()` throw on an unknown permission, panel or role
  (`UnknownPermissionException`, `PanelNotResolvedException` and so on). A typo fails loudly in development
  and tests.
- **Gate, middleware and Blade.** These turn the same errors into a denial and a log entry, so production
  requests get a 403 and not a 500.

## Checks inside database transactions

AzGuard reads grants at a confirmed state version. An open application transaction on the **same connection**
may hold an old snapshot or uncommitted grant rows, and the engine cannot tell them apart. So a check inside
such a transaction **denies**:

- the reason is `source_error`;
- the log code is `invalid_configuration.authority_transaction`.

```php
DB::transaction(function () use ($user) {
    $user->hasPermission(PostPermission::View);   // false: grants are on the default connection
});
```

Pick one of these:

- **Check before the transaction.** This is usually the right order anyway: authorize first, then write.
- **Use a dedicated connection** to the same database: `AZGUARD_DB_CONNECTION=azguard` (see
  [Installation](/getting-started/installation#optional-a-dedicated-database-connection)). Reads then happen
  outside the application transaction, and the same check returns `true`.

Changes are not affected. `grantRole()` and the other change methods may run inside an application
transaction: they join it, and their events are published after the root commit. Tests under
`RefreshDatabase` are handled by `InteractsWithAzGuard`.

## Revocation and caching

- **Revocation.** After a revocation commits, every new request or job no longer sees the revoked grant. With
  `consistency(refresh: StateRefresh::Check)`, the next check in the same request does not see it either.
- **Expiry.** Expired grants never apply, not even from a cache. A grant with `expiresAt = now` has already
  expired.
- **Not cached.** Policies, hooks, restrictions and conditions run on every check. Only grant sets are cached,
  keyed by the panel state version.
- **Already started checks.** A check that began before the revocation can still finish with the old set.
  Guard critical actions inside the action itself, for example by re-checking in the job that performs it.

See [Performance and consistency](/advanced/performance).
