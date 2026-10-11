<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Contracts\Tenancy\TenantDirectory;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Support\ModelKey;
use AzGuard\Tenancy\ModelTenantDefinition;

/**
 * Default tenant directory over the tenant model of a panel. It lists tenants for the interface and decides
 * nothing about membership; a panel without tenants offers none.
 */
final readonly class ModelTenantDirectory implements TenantDirectory
{
    public function __construct(private ?ModelTenantDefinition $definition) {}

    /**
     * @return list<TenantOption>
     */
    public function search(string $term, LookupContext $lookup, int $limit): array
    {
        if ($this->definition === null || $limit < 1) {
            return [];
        }
        $query = $this->definition->model()::query();
        ModelLookup::match($query, $term);
        $options = [];

        foreach ($query->orderBy($query->getModel()->getQualifiedKeyName())->limit($limit)->get() as $record) {
            $options[] = new TenantOption(TenantRef::of($this->definition->type(), ModelKey::of($record)), ModelLookup::label($record));
        }

        return $options;
    }

    public function describe(TenantRef $tenant, LookupContext $lookup): ?TenantOption
    {
        $record = $this->definition?->resolve($tenant);

        return $record === null ? null : new TenantOption($tenant, ModelLookup::label($record));
    }
}
