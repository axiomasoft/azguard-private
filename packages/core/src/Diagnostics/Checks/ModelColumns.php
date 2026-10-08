<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Sources\Database\DatabaseSource;

/**
 * `storage.schema` of a database source: every field the grant models and the panel keep outside `meta` has its
 * column in the table of the storage.
 *
 * @internal
 */
final readonly class ModelColumns implements DoctorCheck
{
    public function __construct(private DatabaseSource $source) {}

    public function key(): string
    {
        return 'storage.schema';
    }

    public function run(DoctorContext $context): iterable
    {
        $panel = $context->panel();

        if ($panel === null) {
            return;
        }

        foreach ($this->source->missingModelColumns($panel) as $missing) {
            yield DoctorFinding::error($this->key(), 'Table '.$missing['table'].' lacks the columns '.implode(', ', $missing['columns']).' of the fields of '
                .$missing['model'].': add them in a migration of the application.', details: ['table' => $missing['table'], 'columns' => $missing['columns']]);
        }
    }
}
