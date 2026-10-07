<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;

/**
 * A stored grant was removed because its expiry had passed. `kind` is `role` or `permission`; exactly one of `role`
 * and `permission` is set.
 *
 * @api
 */
final readonly class GrantExpired extends AccessEvent
{
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $panel,
        TenantRef $tenant,
        ?ActorRef $actor,
        string $correlationId,
        CodeStateToken|StateToken $state,
        public SubjectRef $subject,
        public string $kind,
        public ?RoleKey $role,
        public ?PermissionPattern $permission,
        public AssignmentScopeRef $context,
        public string $origin,
        public ?DateTimeImmutable $expiredAt,
    ) {
        parent::__construct($eventId, $occurredAt, $panel, $tenant, $actor, $correlationId, $state);
    }

    public function type(): EventType
    {
        return EventType::GrantExpired;
    }

    public function subject(): SubjectRef
    {
        return $this->subject;
    }

    protected function data(): array
    {
        return [
            'subject' => self::subjectOf($this->subject),
            'kind' => $this->kind,
            'role' => $this->role?->full(),
            'permission' => $this->permission?->full(),
            'context' => self::context($this->context),
            'origin' => $this->origin,
            'expired_at' => self::moment($this->expiredAt),
        ];
    }
}
