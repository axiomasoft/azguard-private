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
 * A stored role grant was removed by a revocation; the values are those the grant had.
 *
 * @api
 */
final readonly class RoleRevoked extends AccessEvent
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
    ) {
        parent::__construct($eventId, $occurredAt, $panel, $tenant, $actor, $correlationId, $state);
    }

    public function type(): EventType
    {
        return EventType::RoleRevoked;
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
        ];
    }
}
