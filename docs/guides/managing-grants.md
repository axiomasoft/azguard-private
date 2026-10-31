# Authorizing role assignments

Grant and revoke methods are trusted backend writes. They validate the change, but they do not decide whether
the caller may manage another user's roles. Authenticate and authorize on the server for **each** operation,
including direct HTTP, Livewire and Filament calls. Hiding an action in the UI is not authorization.

The executable host service example `tests/Fixtures/Changes/AuthorizedRoleAssignments.php` and its
tests in `tests/Feature/Changes/AuthorizedAssignmentsExampleTest.php` use the CRM fixture's policy:
an authenticated project tenant-admin may grant or revoke only `analyst`, only for another member of the
same tenant, and only in an active project the actor manages. The service reloads the actor, target, tenant
and project, checks membership, the exact role and project, and the actor's current authority before calling
the real `grantRole()` or `revokeRole()`. Host record and membership reloads use the write PDO; role qualification
explicitly uses `onPrimary()`. The CRM panel also uses `StateRefresh::Check`. It deliberately disallows tenant-wide and all-scope changes. Adapt
the policy to your application; a role catalog or a valid scope alone is not permission to assign it.

For example, a controller can validate a narrow payload and obtain the actor from its configured guard:

```php
// $data contains only validated target, tenant, project, role and grant fields.
// Reject unexpected fields, including actor, actor_id and system_reason.
$actorId = auth('web')->id();
$result = $assignments->change(
    $actorId === null ? null : (int) $actorId,
    $data['target'], $data['tenant'], $data['project'], $data['role'], $data['grant'],
);
```

The service's actor ID is trusted server context, not a field from `$data`. Resolve selected IDs to actual
records and check their ownership and the actor's authority; do not accept a client-provided actor, system
reason or arbitrary `actingAs()` argument. The example uses `AzGuard::actingAs($actor, ...)`, which scopes
the underlying `ActingActor` frame and restores it even on exceptions. Actor attribution is separate from
the authorization decision.

A user-initiated queued job must carry a server-derived actor identifier, reload the actor and repeat the
same checks when it executes. Use fresh primary reads and the host's appropriate authorization refresh policy;
do not serialize a previous allow decision or assume a replica is current. If a concurrent revoke must be
atomic with the assignment, use a host protocol coordinating both operations, as described in
[Consistency](../advanced/consistency.md). The example checks before writing but does not lock host membership
and authority against a concurrent change.

Trusted console commands and maintenance jobs may call the write API with an explicit, server-owned system
reason, for example `AzGuard::actingAs('maintenance: backfill', fn () => $target->grantRole('analyst', on: $project))`.
Keep that entry point and reason inaccessible to untrusted input; a user job is not a maintenance job merely
because it runs in a worker. An application may add server-derived request ID, guard and actor identifier to
its own opt-in audit metadata. Never treat client-supplied metadata as authenticated attribution. The audit
journal records what trusted application code attributed; it is neither tamper-proof nor nonrepudiation.

See [Granting access](./granting-access.md) for write operations and [Security and trust model](../advanced/security.md)
for the surrounding boundaries.
