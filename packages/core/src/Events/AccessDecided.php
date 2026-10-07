<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;

/**
 * An access decision, published only by a panel that traces decisions. It reads, never changes: it does not raise the
 * state version, passes no change pipe and is published when the decision is made — after the commit when the decision
 * was made inside a transaction the package itself holds open.
 *
 * @api
 */
final readonly class AccessDecided extends AccessEvent
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
        public PermissionKey $permission,
        public AssignmentScopeRef $context,
        public Effect $effect,
        public DecisionReason $reason,
        public ?string $component,
    ) {
        parent::__construct($eventId, $occurredAt, $panel, $tenant, $actor, $correlationId, $state);
    }

    public function type(): EventType
    {
        return EventType::AccessDecided;
    }

    public function subject(): SubjectRef
    {
        return $this->subject;
    }

    protected function data(): array
    {
        return [
            'subject' => self::subjectOf($this->subject),
            'permission' => $this->permission->full(),
            'context' => self::context($this->context),
            'effect' => $this->effect->value,
            'reason' => $this->reason->value,
            'component' => $this->component,
        ];
    }
}
