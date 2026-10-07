<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;

/**
 * The expiry or fields of a stored role grant changed; the values are the new ones. A migration from a former key of
 * the role also changes the key: `previousRole` is then the former key, otherwise null.
 *
 * @api
 */
final readonly class RoleGrantUpdated extends AccessEvent
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $panel,
        TenantRef $tenant,
        ?ActorRef $actor,
        string $correlationId,
        CodeStateToken|StateToken $state,
        public SubjectRef $subject,
        public RoleKey $role,
        public AssignmentScopeRef $context,
        public string $origin,
        public ?DateTimeImmutable $expiresAt,
        public array $fields,
        public ?RoleKey $previousRole = null,
    ) {
        parent::__construct($eventId, $occurredAt, $panel, $tenant, $actor, $correlationId, $state);
    }

    public function type(): EventType
    {
        return EventType::RoleGrantUpdated;
    }

    public function subject(): SubjectRef
    {
        return $this->subject;
    }

    protected function data(): array
    {
        return [
            'subject' => self::subjectOf($this->subject),
            'role' => $this->role->full(),
            'context' => self::context($this->context),
            'origin' => $this->origin,
            'expires_at' => self::moment($this->expiresAt),
            'fields' => $this->fields,
            'previous_role' => $this->previousRole?->full(),
        ];
    }
}
