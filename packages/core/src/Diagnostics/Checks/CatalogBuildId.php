<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;

/**
 * `catalog.build_id`, for a production deployment: the build is named by `catalog.build_id` (`AZGUARD_BUILD_ID`).
 * Without it the build id follows only the files of the panel providers, so a deployment that changes other code
 * keeps the id of the previous one.
 *
 * @internal
 */
final readonly class CatalogBuildId implements DoctorCheck
{
    public function key(): string
    {
        return 'catalog.build_id';
    }

    public function run(DoctorContext $context): iterable
    {
        if ($context->isProduction() && $context->config()->configuredBuildId() === null) {
            yield DoctorFinding::warning($this->key(), 'azguard.catalog.build_id is not set: set AZGUARD_BUILD_ID to the id of each deployment.');
        }
    }
}
