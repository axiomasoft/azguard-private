<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Formats;

use AzGuard\Events\AccessDecided;
use AzGuard\Events\AccessEvent;
use AzGuard\Events\GrantExpired;
use AzGuard\Events\PanelStateTouched;
use AzGuard\Events\PermissionCreated;
use AzGuard\Events\PermissionDeleted;
use AzGuard\Events\PermissionGranted;
use AzGuard\Events\PermissionGrantUpdated;
use AzGuard\Events\PermissionRevoked;
use AzGuard\Events\PermissionUpdated;
use AzGuard\Events\RoleGranted;
use AzGuard\Events\RoleGrantUpdated;
use AzGuard\Events\RoleRevoked;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;

/** One event of every event type with fixed values, built through the public constructors. */
final class EventSamples
{
    /** @return array<string, mixed> the arguments every event shares */
    public static function head(bool $code = false): array
    {
        return ['eventId' => '01j0000000000000000000000a', 'occurredAt' => new DateTimeImmutable('2026-10-06T12:00:00Z'), 'panel' => 'crm',
            'tenant' => TenantRef::of('crm.organization', 1), 'actor' => ActorRef::of('crm.user', 3, 'ticket'), 'correlationId' => '01j0000000000000000000000b',
            'state' => $code ? CodeStateToken::of('crm', 'build-1', 'fingerprint') : StateToken::of('default', 'crm', '01j0000000000000000000000c', 7, 1, 'fingerprint')];
    }

    /** @return array<string, AccessEvent> by type value, in the order of `EventType::cases()` */
    public static function all(): array
    {
        $head = self::head();
        $subject = SubjectRef::of('crm.user', 2);
        $context = AssignmentScopeRef::of('crm.project', 1);
        $until = new DateTimeImmutable('2027-01-01T00:00:00Z');
        $grant = ['subject' => $subject, 'context' => $context, 'origin' => 'manual', 'expiresAt' => $until, 'fields' => ['region' => 'R1']];
        $role = RoleKey::of('crm', 'analyst');
        $permission = PermissionPattern::of('crm', 'clients.*');
        $dynamic = ['permission' => PermissionKey::of('crm', 'campaigns.launch'), 'label' => 'Launch', 'group' => 'Marketing', 'description' => null, 'fields' => []];
        $events = [
            new PermissionCreated(...$head, ...$dynamic),
            new PermissionUpdated(...$head, ...$dynamic),
            new PermissionDeleted(...$head, ...$dynamic, removedGrantIds: ['permission:4', 'permission:9']),
            new RoleGranted(...$head, ...$grant, role: $role),
            new RoleRevoked(...$head, ...$grant, role: $role),
            new RoleGrantUpdated(...$head, ...$grant, role: $role),
            new PermissionGranted(...$head, ...$grant, permission: $permission),
            new PermissionRevoked(...$head, ...$grant, permission: $permission),
            new PermissionGrantUpdated(...$head, ...$grant, permission: $permission),
            new GrantExpired(...$head, subject: $subject, kind: 'role', role: $role, permission: null, context: $context, origin: 'import', expiredAt: $until),
            new PanelStateTouched(...$head, previousVersion: 6, reason: 'host import finished'),
            new AccessDecided(...self::head(code: true), subject: $subject, permission: PermissionKey::of('crm', 'clients.view'), context: $context,
                effect: Effect::Allow, reason: DecisionReason::Granted, component: 'database'),
        ];

        return array_combine(array_map(static fn (AccessEvent $event): string => $event->type()->value, $events), $events);
    }
}
