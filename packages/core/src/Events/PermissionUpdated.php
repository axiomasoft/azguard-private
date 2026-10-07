<?php

declare(strict_types=1);

namespace AzGuard\Events;

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\TenantRef;
use DateTimeImmutable;

/**
 * The label, group or description of a dynamic permission changed; the values are the new ones.
 *
 * @api
 */
final readonly class PermissionUpdated extends AccessEvent
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $panel,
        TenantRef $tenant,
        ?ActorRef $actor,
        string $correlationId,
        CodeStateToken|StateToken $state,
        public PermissionKey $permission,
        public ?string $label,
        public ?string $group,
        public ?string $description,
        public array $fields,
    ) {
        parent::__construct($eventId, $occurredAt, $panel, $tenant, $actor, $correlationId, $state);
    }

    public function type(): EventType
    {
        return EventType::PermissionUpdated;
    }

    protected function data(): array
    {
        return [
            'permission' => $this->permission->full(),
            'label' => $this->label,
            'group' => $this->group,
            'description' => $this->description,
            'fields' => $this->fields,
        ];
    }
}
