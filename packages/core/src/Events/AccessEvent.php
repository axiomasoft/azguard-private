<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;
use DateTimeZone;

/**
 * An immutable fact about a change of access or a traced decision, delivered through the Laravel event dispatcher.
 *
 * A change event is published once per effective change, after the root commit that made it durable; a repeat
 * without a difference publishes nothing. `state` is the revision the change produced, not the latest state at the
 * moment of delivery. The payload holds values and references only: no Eloquent model, request, container or secret.
 * Delivery is best effort and not exactly-once.
 *
 * @api
 */
abstract readonly class AccessEvent
{
    public function __construct(
        public string $eventId,
        public DateTimeImmutable $occurredAt,
        public string $panel,
        public TenantRef $tenant,
        public ?ActorRef $actor,
        public string $correlationId,
        public CodeStateToken|StateToken $state,
    ) {}

    abstract public function type(): EventType;

    /** The subject whose access changed or was decided; null for an event that is not about one subject. */
    public function subject(): ?SubjectRef
    {
        return null;
    }

    /**
     * The event as scalars and arrays only: the envelope and the data of its own type.
     *
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type()->value,
            'occurred_at' => self::moment($this->occurredAt),
            'panel' => $this->panel,
            'tenant' => self::tenant($this->tenant),
            'actor' => $this->actor === null ? null : ['type' => $this->actor->type, 'id' => $this->actor->id, 'reason' => $this->actor->reason],
            'correlation_id' => $this->correlationId,
            'state' => $this->state instanceof StateToken
                ? ['kind' => 'state', 'storage' => $this->state->storageId, 'panel' => $this->state->panel, 'incarnation' => $this->state->incarnation,
                    'version' => $this->state->version, 'generation' => $this->state->generation, 'fingerprint' => $this->state->fingerprint]
                : ['kind' => 'code', 'panel' => $this->state->panel, 'build_id' => $this->state->buildId, 'fingerprint' => $this->state->fingerprint],
            ...$this->data(),
        ];
    }

    /** @return array<string, mixed> the data of this event type, after the envelope */
    abstract protected function data(): array;

    /** @return array{type: string, id: string} */
    protected static function subjectOf(SubjectRef $subject): array
    {
        return ['type' => $subject->type(), 'id' => $subject->id()];
    }

    /** @return array{key: string, type: ?string, id: ?string} */
    protected static function tenant(TenantRef $tenant): array
    {
        return ['key' => $tenant->key(), 'type' => $tenant->type(), 'id' => $tenant->id()];
    }

    /** @return array{key: string, type: ?string, id: ?string} */
    protected static function context(AssignmentScopeRef $context): array
    {
        return ['key' => $context->key(), 'type' => $context->type(), 'id' => $context->id()];
    }

    protected static function moment(?DateTimeImmutable $moment): ?string
    {
        return $moment?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
