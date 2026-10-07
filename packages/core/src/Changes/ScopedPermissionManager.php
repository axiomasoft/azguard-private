<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Contracts\Changes\PermissionManager;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Schema\SchemaBuilder;

/**
 * The permissions of one panel and tenant: listed from the schema, changed through the change pipeline, which refuses
 * a dynamic permission on a panel without `dynamicPermissions()`.
 *
 * @internal made by the panel managers
 */
final readonly class ScopedPermissionManager implements PermissionManager
{
    public function __construct(private ChangePipeline $pipeline, private SchemaBuilder $schema, private Panel $panel, private TenantRef $tenant) {}

    public function all(): array
    {
        return $this->schema->for($this->panel, $this->tenant)->permissions();
    }

    public function create(string $name, ?string $label = null, ?string $group = null, array $fields = []): ChangeResult
    {
        return $this->pipeline->createPermission($this->panel, $this->tenant, $name, new PermissionDetails($label, $group, null, $fields));
    }

    public function update(string $name, PermissionDetails $details): ChangeResult
    {
        return $this->pipeline->updatePermission($this->panel, $this->tenant, $name, $details);
    }

    public function delete(string $name): ChangeResult
    {
        return $this->pipeline->deletePermission($this->panel, $this->tenant, $name);
    }
}
