<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Sources\Database\DatabaseSource;

/**
 * `fields.meta` of a database source: a decision field kept in `meta` is read from JSON on every check and cannot be
 * filtered by the database; a column serves large volumes.
 *
 * @internal
 */
final readonly class DecisionFieldsInMeta implements DoctorCheck
{
    public function __construct(private DatabaseSource $source) {}

    public function key(): string
    {
        return 'fields.meta';
    }

    public function run(DoctorContext $context): iterable
    {
        $panel = $context->panel();

        if ($panel === null) {
            return;
        }

        foreach ($this->source->decisionFieldsInMeta($panel) as ['kind' => $kind, 'field' => $field]) {
            yield DoctorFinding::warning($this->key(), 'The decision field "'.$field.'" of '.$kind.' grants in panel '.$panel->id()
                .' is kept in meta: give it a column when grants are many or filtered by it.', details: ['kind' => $kind, 'field' => $field]);
        }
    }
}
