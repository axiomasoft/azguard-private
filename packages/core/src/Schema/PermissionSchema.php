<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\PermissionKey;
use JsonSerializable;

/**
 * One permission of a panel as an interface shows it: who decides it, which sources can give it and where.
 */
final readonly class PermissionSchema implements JsonSerializable
{
    /**
     * @param  string  $name  the name for Gate and the frontend, with the panel prefix when the panel has one
     * @param  string  $owner  id of the source that defines the permission
     * @param  string|null  $decidedBy  `policy:Class@method` or `gate:ability` of the bound policy, a hint for interfaces
     * @param  list<string>  $sources  ids of the sources that can give the permission; empty when a policy alone decides it
     * @param  list<string>  $contextTypes  assignment scope types the permission can be granted in
     */
    public function __construct(
        public PermissionKey $key,
        public string $label,
        public ?string $resourceGroup,
        public ?string $description,
        public PermissionAuthority $authority,
        public bool $dynamic,
        public string $name,
        public string $owner,
        public ?string $decidedBy,
        public array $sources,
        public array $contextTypes,
    ) {}

    /**
     * Whether a grant can give the permission; a policy-only permission is never assigned.
     */
    public function grantable(): bool
    {
        return $this->authority === PermissionAuthority::Grants;
    }

    /**
     * @return array{key: string, local: string, name: string, label: string, group: ?string, description: ?string, authority: string, grantable: bool, dynamic: bool, owner: string, decided_by: ?string, sources: list<string>, context_types: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key->full(),
            'local' => $this->key->local(),
            'name' => $this->name,
            'label' => $this->label,
            'group' => $this->resourceGroup,
            'description' => $this->description,
            'authority' => $this->authority->value,
            'grantable' => $this->grantable(),
            'dynamic' => $this->dynamic,
            'owner' => $this->owner,
            'decided_by' => $this->decidedBy,
            'sources' => $this->sources,
            'context_types' => $this->contextTypes,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
