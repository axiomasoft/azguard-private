<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Kernel\Identity\TenantRef;
use JsonSerializable;

/**
 * What a panel offers to an interface for one tenant: permissions with the dynamic ones of that tenant, code roles,
 * grant fields and the types of tenants, assignment scopes and subjects with their directories.
 *
 * A snapshot: scalars, value objects and class names only. Models, closures, services and the parameters of sources
 * and plugins are never part of it. A panel without a source that stores grants is read-only (`writable` is false).
 */
final readonly class PanelSchema implements JsonSerializable
{
    /**
     * @internal built by the schema builder
     *
     * @param  list<PermissionSchema>  $permissions  sorted by full name
     * @param  list<RoleSchema>  $roles  sorted by key
     * @param  array<string, list<FieldSchema>>  $fields  by `FieldTarget` value, each sorted by name
     * @param  list<TenantTypeSchema>  $tenants
     * @param  list<AssignmentScopeTypeSchema>  $scopes  sorted by type
     * @param  list<SubjectTypeSchema>  $subjects  in declaration order
     */
    public function __construct(
        public string $panel,
        public string $label,
        public bool $writable,
        public TenantRef $tenant,
        private array $permissions,
        private array $roles,
        private array $fields,
        private array $tenants,
        private array $scopes,
        private array $subjects,
    ) {}

    /** @return list<PermissionSchema> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * Permissions by their resource group; a permission without a group is under the empty name.
     *
     * @return array<string, list<PermissionSchema>>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->permissions as $permission) {
            $groups[$permission->resourceGroup ?? ''][] = $permission;
        }
        ksort($groups, SORT_STRING);

        return $groups;
    }

    /** @return list<RoleSchema> */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return list<FieldSchema> */
    public function fields(FieldTarget $target): array
    {
        return $this->fields[$target->value] ?? [];
    }

    /** @return list<TenantTypeSchema> */
    public function tenants(): array
    {
        return $this->tenants;
    }

    /** @return list<AssignmentScopeTypeSchema> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /** @return list<SubjectTypeSchema> */
    public function subjects(): array
    {
        return $this->subjects;
    }

    /**
     * Null, booleans, numbers, strings and arrays only.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $fields = [];

        foreach (FieldTarget::cases() as $target) {
            $fields[$target->value] = array_map(static fn (FieldSchema $field): array => $field->toArray(), $this->fields($target));
        }

        return [
            'panel' => $this->panel,
            'label' => $this->label,
            'writable' => $this->writable,
            'tenant' => ['key' => $this->tenant->key(), 'type' => $this->tenant->type(), 'id' => $this->tenant->id()],
            'permissions' => array_map(static fn (PermissionSchema $permission): array => $permission->toArray(), $this->permissions),
            'groups' => array_map(
                static fn (array $permissions): array => array_map(static fn (PermissionSchema $permission): string => $permission->key->full(), $permissions),
                $this->groups(),
            ),
            'roles' => array_map(static fn (RoleSchema $role): array => $role->toArray(), $this->roles),
            'fields' => $fields,
            'tenants' => array_map(static fn (TenantTypeSchema $tenant): array => $tenant->toArray(), $this->tenants),
            'scopes' => array_map(static fn (AssignmentScopeTypeSchema $scope): array => $scope->toArray(), $this->scopes),
            'subjects' => array_map(static fn (SubjectTypeSchema $subject): array => $subject->toArray(), $this->subjects),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
