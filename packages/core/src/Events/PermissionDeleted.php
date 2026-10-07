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
 * A dynamic permission was deleted; the grants of exactly its name in the tenant were revoked in the same change.
 *
 * @api
 */
final readonly class PermissionDeleted extends AccessEvent
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $removedGrantIds  ids of the stored grants the deletion removed
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
        public array $removedGrantIds = [],
    ) {
        parent::__construct($eventId, $occurredAt, $panel, $tenant, $actor, $correlationId, $state);
    }

    public function type(): EventType
    {
        return EventType::PermissionDeleted;
    }

    protected function data(): array
    {
        return [
            'permission' => $this->permission->full(),
            'label' => $this->label,
            'group' => $this->group,
            'description' => $this->description,
            'fields' => $this->fields,
            'removed_grants' => $this->removedGrantIds,
        ];
    }
}
