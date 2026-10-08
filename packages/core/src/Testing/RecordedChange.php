<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Events\AccessEvent;
use AzGuard\Events\EventType;
use AzGuard\Events\GrantExpired;
use AzGuard\Events\PermissionGranted;
use AzGuard\Events\PermissionGrantUpdated;
use AzGuard\Events\PermissionRevoked;
use AzGuard\Events\RoleGranted;
use AzGuard\Events\RoleGrantUpdated;
use AzGuard\Events\RoleRevoked;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

/**
 * One change the fake saw, taken from the event the change published after its commit. A change of the permission
 * catalog or of the panel state has no subject, role or context.
 *
 * @api
 */
final readonly class RecordedChange
{
    public function __construct(
        public EventType $type,
        public string $panel,
        public TenantRef $tenant,
        public ?ActorRef $actor,
        public ?SubjectRef $subject,
        public ?RoleKey $role,
        public ?PermissionPattern $permission,
        public ?AssignmentScopeRef $context,
        public AccessEvent $event,
    ) {}

    public static function of(AccessEvent $event): self
    {
        $role = match (true) {
            $event instanceof RoleGranted, $event instanceof RoleRevoked, $event instanceof RoleGrantUpdated => $event->role,
            $event instanceof GrantExpired => $event->role,
            default => null,
        };
        $permission = match (true) {
            $event instanceof PermissionGranted, $event instanceof PermissionRevoked, $event instanceof PermissionGrantUpdated => $event->permission,
            $event instanceof GrantExpired => $event->permission,
            default => null,
        };
        $context = match (true) {
            $event instanceof RoleGranted, $event instanceof RoleRevoked, $event instanceof RoleGrantUpdated,
            $event instanceof PermissionGranted, $event instanceof PermissionRevoked, $event instanceof PermissionGrantUpdated,
            $event instanceof GrantExpired => $event->context,
            default => null,
        };

        return new self($event->type(), $event->panel, $event->tenant, $event->actor, $event->subject(), $role, $permission, $context, $event);
    }
}
