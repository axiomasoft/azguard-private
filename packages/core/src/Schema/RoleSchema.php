<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Roles\BaseRole;
use JsonSerializable;

/**
 * A code role of a panel. Its definition changes only in PHP, so an interface shows it read-only.
 */
final readonly class RoleSchema implements JsonSerializable
{
    /** A code role is never edited from an interface. */
    public bool $editable;

    /**
     * @param  class-string<BaseRole>  $class
     * @param  bool  $grantable  whether the role may be granted by hand
     * @param  bool  $automatic  whether a code rule grants the role; it may also be grantable
     * @param  list<string>  $contextTypes  assignment scope types the role may be granted in
     * @param  list<AssignmentScopeBindingSchema>  $contextBindings
     * @param  list<PermissionPattern>  $permissions
     */
    public function __construct(
        public RoleKey $key,
        public string $label,
        public string $class,
        public bool $grantable,
        public bool $automatic,
        public bool $scopeRequired,
        public array $contextTypes,
        public array $contextBindings,
        public bool $superAdmin,
        public array $permissions,
    ) {
        $this->editable = false;
    }

    /**
     * @return array{key: string, label: string, class: string, editable: bool, grantable: bool, automatic: bool, scope_required: bool, context_types: list<string>, context_bindings: list<array<string, mixed>>, super_admin: bool, permissions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key->key(),
            'label' => $this->label,
            'class' => $this->class,
            'editable' => $this->editable,
            'grantable' => $this->grantable,
            'automatic' => $this->automatic,
            'scope_required' => $this->scopeRequired,
            'context_types' => $this->contextTypes,
            'context_bindings' => array_map(static fn (AssignmentScopeBindingSchema $binding): array => $binding->toArray(), $this->contextBindings),
            'super_admin' => $this->superAdmin,
            'permissions' => array_map(static fn (PermissionPattern $pattern): string => $pattern->local(), $this->permissions),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
