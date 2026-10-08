<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Sources\Database\DatabaseSource;
use DateTimeImmutable;
use DateTimeZone;

/**
 * `grants.dead` of a database source: stored permission grants that give nothing — a permission outside the catalog,
 * a permission decided by a policy rather than by grants, an unregistered scope type — and grants of a subject type
 * the panel does not accept.
 *
 * @internal
 */
final readonly class GrantsDead implements DoctorCheck
{
    public function __construct(private DatabaseSource $source) {}

    public function key(): string
    {
        return 'grants.dead';
    }

    public function run(DoctorContext $context): iterable
    {
        $panel = $context->panel();

        if ($panel === null) {
            return;
        }

        foreach ($this->source->orphanedGrants($panel, new DateTimeImmutable('now', new DateTimeZone('UTC'))) as $orphan) {
            if ($orphan['kind'] === 'permission') {
                yield DoctorFinding::warning($this->key(), $orphan['rows'].' grant(s) of the permission "'.$orphan['name'].'" in tenant '.$orphan['tenant'].' (origin '
                    .$orphan['origin'].') give nothing: the permission is not in the catalog, is not granted by grants, or the scope type is not registered.',
                    details: ['permission' => $orphan['name'], 'tenant' => $orphan['tenant'], 'origin' => $orphan['origin'], 'grants' => $orphan['rows']]);
            }
        }

        foreach ($this->source->unacceptedSubjects($panel) as $subject) {
            yield DoctorFinding::warning($this->key(), $subject['rows'].' '.$subject['kind'].' grant(s) belong to subjects of the type "'.$subject['type']
                .'", which panel '.$panel->id().' does not accept: they give nothing.', details: ['kind' => $subject['kind'], 'subject_type' => $subject['type'], 'grants' => $subject['rows']]);
        }
    }
}
