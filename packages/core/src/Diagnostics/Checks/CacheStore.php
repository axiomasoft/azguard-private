<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\Panel;

/**
 * `cache.store`: a panel that keeps permission sets in a persistent store also expires them.
 *
 * @internal
 */
final readonly class CacheStore implements DoctorCheck
{
    public function key(): string
    {
        return 'cache.store';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($panel);
        }
    }

    /** @return list<DoctorFinding> */
    public static function check(Panel $panel): array
    {
        $settings = $panel->settings();

        if ($settings->cacheStore() === null || $settings->cacheTtl() !== null) {
            return [];
        }

        return [DoctorFinding::error('cache.store', 'Panel '.$panel->id().' keeps permission sets in the store "'.$settings->cacheStore()
            .'" without a ttl: a persistent store needs cache.ttl.', 'panel:'.$panel->id(),
            ['store' => $settings->cacheStore(), 'origin' => $settings->origin('cache.ttl')])];
    }
}
