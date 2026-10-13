<?php

declare(strict_types=1);

namespace AzGuard\Tests\Support;

use AzGuard\Audit\Internal\AuditSchema;
use AzGuard\AzGuard;

/**
 * The audit journal table for a test that attaches `AuditPlugin`, created the way the published audit migration
 * creates it (unless the storage already has it). Core storages have no journal table, so only such tests call this.
 */
final class AuditTable
{
    public static function create(string $storage = 'default'): void
    {
        $descriptor = app(AzGuard::class)->storage($storage);

        if (! AuditSchema::exists($descriptor)) {
            AuditSchema::create($descriptor);
        }
    }
}
