<?php

declare(strict_types=1);

namespace AzGuard\Registry\Contracts;

/**
 * Builds the list of PermissionDefinition objects for a given panel.
 * Each builder covers one source: enum, #[GateAbility], or config.
 *
 * @api
 */
interface PermissionCatalogBuilder
{
    /**
     * @return list<PermissionDefinition>
     */
    public function build(string $panelId): array;

    /**
     * Whether this builder covers the given panel.
     * CompositePermissionCatalog calls build() only when supports() returns true.
     */
    public function supports(string $panelId): bool;
}
