<?php

declare(strict_types=1);

namespace AzGuard\Audit\Internal\Checks;

use AzGuard\Audit\Internal\AuditSchema;
use AzGuard\AzGuard;
use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;

/**
 * `audit.table`: the panel with the audit plugin stores grants in a writer whose storage has the journal table in the
 * shape of `AuditSchema`, so a change does not fail on its first journal row.
 *
 * @internal
 */
final readonly class AuditTableExists implements DoctorCheck
{
    public function __construct(private AzGuard $azguard) {}

    public function key(): string
    {
        return 'audit.table';
    }

    public function run(DoctorContext $context): iterable
    {
        $panel = $context->panel();

        if ($panel === null) {
            return;
        }
        $writer = $panel->writer();

        if ($writer === null) {
            yield DoctorFinding::error($this->key(), 'Panel '.$panel->id().' has the audit plugin but no database source that stores its grants, so no journal is written.');

            return;
        }
        $storage = $this->azguard->storage($writer->storage);

        if (! AuditSchema::exists($storage)) {
            yield DoctorFinding::error($this->key(), 'The storage '.$storage->id.' of panel '.$panel->id().' has no audit_log table: '
                .($storage->id === 'default'
                    ? 'run php artisan vendor:publish --tag=azguard-audit-migrations and php artisan migrate.'
                    : 'run php artisan azguard:audit:migration '.$storage->id.' and php artisan migrate.'), details: ['storage' => $storage->id]);

            return;
        }
        $differences = AuditSchema::differences($storage);

        if ($differences !== []) {
            yield DoctorFinding::error($this->key(), 'The audit_log table of storage '.$storage->id.' differs from the audit schema: '.implode('; ', $differences).'.',
                details: ['storage' => $storage->id, 'differences' => $differences]);
        }
    }
}
