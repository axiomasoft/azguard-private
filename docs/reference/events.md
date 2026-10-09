# Events

AzGuard dispatches immutable events (`AzGuard\Events\*`) through Laravel's event dispatcher.

- **When.** A change event is published once for each effective change, **after the root commit** that made it
  durable. A repeat that changes nothing publishes nothing.
- **Delivery** is best effort, not exactly-once.
- **Payload.** Values and references only: no Eloquent models, requests or secrets.
- **Serialization.** `toArray()` returns scalars and arrays only. Queue it, log it or send it to an audit store.

```php
use AzGuard\Events\RoleGranted;
use Illuminate\Support\Facades\Event;

Event::listen(function (RoleGranted $event): void {
    logger()->info('role granted', $event->toArray());
    // $event->subject->id(), $event->role->key(), $event->actor?->reason, $event->expiresAt
});
```

## Envelope (every event)

| Property | Type | Meaning |
|---|---|---|
| `eventId` | `string` | Unique id |
| `occurredAt` | `DateTimeImmutable` | When the change happened (`toArray()` gives UTC ISO-8601) |
| `panel` | `string` | Panel id |
| `tenant` | `TenantRef` | Tenant, or the global tenant |
| `actor` | `?ActorRef` | Who made the change: `type`, `id`, `reason`. A change made with `AzGuard::actingAs('reason', …)` has type `azguard.system` |
| `correlationId` | `string` | Shared by all events of one change |
| `state` | `StateToken\|CodeStateToken` | The revision the change produced |
| `type()` | `EventType` | For example `role.granted` |

## Event types

| Class | `type()` | Extra properties |
|---|---|---|
| `RoleGranted` | `role.granted` | `subject`, `role`, `context`, `origin`, `expiresAt`, `fields` |
| `RoleRevoked` | `role.revoked` | The same; the values the grant had |
| `RoleGrantUpdated` | `role.grant_updated` | The same (new values), plus `previousRole` after `azguard:roles:rename-key` |
| `PermissionGranted` | `permission.granted` | `subject`, `permission` (name or pattern), `context`, `origin`, `expiresAt`, `fields` |
| `PermissionRevoked` | `permission.revoked` | The same |
| `PermissionGrantUpdated` | `permission.grant_updated` | The same (new values) |
| `GrantExpired` | `grant.expired` | `subject`, `kind` (`role`/`permission`), `role` or `permission`, `context`, `origin`, `expiredAt`. Published when an expired grant is pruned |
| `PermissionCreated` | `permission.created` | `permission`, `label`, `group`, `description`, `fields` (dynamic permissions) |
| `PermissionUpdated` | `permission.updated` | The same (new values) |
| `PermissionDeleted` | `permission.deleted` | The same, plus `removedGrantIds` |
| `PanelStateTouched` | `panel.touched` | `previousVersion`, `reason`. Published by `azguard:state:reset` and similar touches |
| `AccessDecided` | `access.decided` | `subject`, `permission`, `context`, `effect`, `reason` (`DecisionReason`), `component`. Only when `trace_decisions` is on |

`context` is the assignment scope (`AssignmentScopeRef`, global unless the grant is scoped). `origin` names
where the grant came from: `manual` for the API and the CLI.

## Testing

`Event::fake()` works as usual. `AzGuard::fake()` records grant and revoke changes, so you can assert them
without events. See [Testing](/guides/testing).
