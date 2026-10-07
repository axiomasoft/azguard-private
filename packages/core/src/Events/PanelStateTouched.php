<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;

/**
 * The state version of a panel was raised on purpose, without a change of a grant, so every cached decision of the
 * panel is renewed. `previousVersion` is the version before the touch.
 *
 * @api
 */
final readonly class PanelStateTouched extends AccessEvent
{
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $panel,
        TenantRef $tenant,
        ?ActorRef $actor,
        string $correlationId,
        CodeStateToken|StateToken $state,
        public int $previousVersion,
        public string $reason,
    ) {
        parent::__construct($eventId, $occurredAt, $panel, $tenant, $actor, $correlationId, $state);
    }

    public function type(): EventType
    {
        return EventType::PanelTouched;
    }

    protected function data(): array
    {
        return ['previous_version' => $this->previousVersion, 'reason' => $this->reason];
    }
}
