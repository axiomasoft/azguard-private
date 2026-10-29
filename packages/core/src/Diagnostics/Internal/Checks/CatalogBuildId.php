<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Internal\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;

/**
 * `catalog.build_id`, for a production deployment: the build is named by `catalog.build_id` (`AZGUARD_BUILD_ID`).
 * Without it the build id follows only the files of the panel providers, so a deployment that changes other code
 * keeps the id of the previous one. That is an error when a catalog cache file or a persistent panel cache store can
 * serve the stale build, a warning otherwise.
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
        if (! $context->isProduction() || $context->config()->configuredBuildId() !== null) {
            return;
        }

        $reason = $this->cachingReason($context);

        if ($reason === null) {
            yield DoctorFinding::warning($this->key(), 'azguard.catalog.build_id is not set: set AZGUARD_BUILD_ID to the id of each deployment.');

            return;
        }

        yield DoctorFinding::error($this->key(), 'azguard.catalog.build_id is not set while '.$reason.': a deployment that changes code outside the panel providers keeps the previous build id and reuses its cached data. Set AZGUARD_BUILD_ID to an immutable id of each deployment, such as the commit SHA or the image digest.');
    }

    /** What can serve the data of another build, or null when nothing persists it. */
    private function cachingReason(DoctorContext $context): ?string
    {
        $path = $context->config()->catalogCachePath() ?? base_path('bootstrap/cache/azguard.php');

        if (is_file($path)) {
            return 'the catalog cache file exists';
        }

        foreach ($context->panels() as $panel) {
            if ($panel->settings()->cacheStore() !== null) {
                return 'panel '.$panel->id().' keeps permission sets in the persistent store "'.$panel->settings()->cacheStore().'"';
            }
        }

        return null;
    }
}
