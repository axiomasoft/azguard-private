<?php

declare(strict_types=1);

use AzGuard\Events\AccessDecided;
use AzGuard\Events\AccessEvent;
use AzGuard\Events\EventType;
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
use AzGuard\Tests\Fixtures\Events\EventWorld;

/** @return array<string, mixed> the arguments every event shares, with fixed values */
function catalogHead(bool $code = false): array
{
    return ['eventId' => '01j0000000000000000000000a', 'occurredAt' => new DateTimeImmutable('2026-10-06T12:00:00Z'), 'panel' => 'crm',
        'tenant' => TenantRef::of('crm.organization', 1), 'actor' => ActorRef::of('crm.user', 3, 'ticket'), 'correlationId' => '01j0000000000000000000000b',
        'state' => $code ? CodeStateToken::of('crm', 'build-1', 'fingerprint') : StateToken::of('default', 'crm', '01j0000000000000000000000c', 7, 1, 'fingerprint')];
}

/** @return array<string, AccessEvent> one event of every type, by its type value */
function catalogEvents(): array
{
    $head = catalogHead();
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
        new AccessDecided(...catalogHead(code: true), subject: $subject, permission: PermissionKey::of('crm', 'clients.view'), context: $context,
            effect: Effect::Allow, reason: DecisionReason::Granted, component: 'database'),
    ];

    return array_combine(array_map(fn (AccessEvent $event): string => $event->type()->value, $events), $events);
}

it('keeps the event types and the payload shape of every event as a JSON snapshot', function (): void {
    $snapshot = [
        'types' => array_map(fn (EventType $type): string => $type->value, EventType::cases()),
        'events' => array_map(fn (AccessEvent $event): array => $event->toArray(), catalogEvents()),
        // A grant moved from a former key of its role names the key it had.
        'migration' => (new RoleGrantUpdated(...catalogHead(), subject: SubjectRef::of('crm.user', 2), role: RoleKey::of('crm', 'auditor'),
            context: AssignmentScopeRef::of('crm.project', 1), origin: 'manual', expiresAt: null, fields: [],
            previousRole: RoleKey::of('crm', 'inspector')))->toArray(),
    ];
    $actual = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    $path = dirname(__DIR__, 2).'/Fixtures/Events/catalog.json';

    if (getenv('AZGUARD_UPDATE_SNAPSHOTS') === '1') {
        file_put_contents($path, $actual);
    }

    expect($actual)->toBe(file_get_contents($path));
});

it('has one event per type, each final, readonly and made of plain values', function (): void {
    $events = catalogEvents();

    expect(array_keys($events))->toBe(array_map(fn (EventType $type): string => $type->value, EventType::cases()));

    foreach ($events as $type => $event) {
        $class = new ReflectionClass($event);

        expect($class->isFinal())->toBeTrue()->and($class->isReadOnly())->toBeTrue()->and($event)->toBeInstanceOf(AccessEvent::class)
            ->and($event->type()->value)->toBe($type)->and(EventWorld::plain($event->toArray()))->toBeTrue()
            ->and(array_slice(array_keys($event->toArray()), 0, 7))->toBe(['event_id', 'type', 'occurred_at', 'panel', 'tenant', 'actor', 'correlation_id']);
    }
});

it('serializes a change event state and a code state differently but both as plain values', function (): void {
    $events = catalogEvents();

    expect($events['role.granted']->toArray()['state']['kind'])->toBe('state')->and($events['access.decided']->toArray()['state']['kind'])->toBe('code')
        ->and($events['role.granted']->subject()?->key())->toBe('crm.user:2')->and($events['panel.touched']->subject())->toBeNull();
});
