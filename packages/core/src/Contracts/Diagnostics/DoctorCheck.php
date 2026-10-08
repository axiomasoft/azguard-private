<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Diagnostics;

use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;

/**
 * One health check of `azguard:doctor`. A check only reads: it writes nothing to a storage and changes no cache.
 *
 * A check of a source, a panel or a plugin runs once for each selected panel it belongs to and reads that panel
 * from `DoctorContext::panel()`. An exception thrown by a check is reported as an error under `<key>.failed`;
 * the other checks still run.
 *
 * @spi
 */
interface DoctorCheck
{
    /** A stable key of the check, such as `storage.schema` or `acme.folders`. */
    public function key(): string;

    /** @return iterable<DoctorFinding> the problems found; nothing when the check passes */
    public function run(DoctorContext $context): iterable;
}
