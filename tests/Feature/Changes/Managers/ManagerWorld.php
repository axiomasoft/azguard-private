<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Changes\Managers;

use AzGuard\Changes\PanelManagers;
use AzGuard\Panels\Panel;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;

/** Managers over the CRM change stand, and stored rows the current code would no longer write. */
final class ManagerWorld
{
    public static function managers(Panel $panel, int $tenant = 1, string $origin = 'manual'): PanelManagers
    {
        return PanelManagers::for($panel, ChangeWorld::tenant($tenant), $origin);
    }

    /**
     * Inserts a grant row as an older build or an import left it, bypassing the change pipeline, and returns its id.
     *
     * @param  'role'|'permission'  $kind
     * @param  array<string, mixed>  $meta
     */
    public static function stored(string $kind, string $key, int $user, ?string $context = null, int $tenant = 1, string $origin = 'manual',
        ?string $until = null, array $meta = []): string
    {
        $id = 0;
        CrmWorld::storage()->mutate('crm', static function (StorageMutation $mutation) use ($kind, $key, $user, $context, $tenant, $origin, $until, $meta, &$id): void {
            [$type, $ref] = $context === null ? [null, null] : explode(':', $context, 2);
            $id = (int) $mutation->table($kind.'_grants')->insertGetId([
                'panel' => 'crm', 'tenant_key' => 'crm.organization:'.$tenant, 'tenant_type' => 'crm.organization', 'tenant_id' => (string) $tenant,
                'context_key' => $context ?? 'global', 'context_type' => $type, 'context_id' => $ref,
                'subject_type' => 'crm.user', 'subject_id' => (string) $user, $kind => $key, 'origin' => $origin,
                'expires_at' => $until, 'meta' => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR),
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]);
            $mutation->touch('crm');
        });

        return $kind.':'.$id;
    }

    /** @return array<string, mixed>|null the raw row of a grant id */
    public static function row(string $id): ?array
    {
        [$kind, $number] = explode(':', $id, 2);
        $row = CrmWorld::storage()->table($kind.'_grants')->where('id', (int) $number)->first();

        return $row === null ? null : (array) $row;
    }
}
