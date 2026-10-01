<?php

declare(strict_types=1);

namespace AzGuard\Filament\Permissions;

/**
 * Discovers the permission subjects (Filament resources and pages) that should
 * own permissions for a given AzGuard panel.
 *
 * Abstracted behind an interface so the catalog builder and the generator can
 * be driven by the live Filament panel ({@see FilamentDiscovery}) or by a
 * fixed list in tests.
 */
interface PermissionDiscovery
{
    /**
     * @return list<PermissionSubject>
     */
    public function subjects(string $panelId): array;
}
