<?php

declare(strict_types=1);

namespace AzGuard\Plugins\Audit;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Sources\Database\DatabaseSource;

/**
 * `audit.table`: the panel with the audit plugin stores grants in a database source whose storage has the journal
 * table, so a change does not fail on its first journal row.
 *
 * @internal
 */
final readonly class AuditTableExists implements DoctorCheck
{
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

        if (! $writer instanceof DatabaseSource) {
            yield DoctorFinding::error($this->key(), 'Panel '.$panel->id().' has the audit plugin but no database source that stores its grants, so no journal is written.');

            return;
        }

        if (! $writer->hasTable('audit_log')) {
            yield DoctorFinding::error($this->key(), 'The storage '.$writer->boundStorage()->id().' of panel '.$panel->id()
                .' has no audit_log table: publish and run the AzGuard migrations.', details: ['storage' => $writer->boundStorage()->id()]);
        }
    }
}
