<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization\Cache;

use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;

final class FencedCacheSource extends CacheSource implements FencesReads
{
    public int $stateReads = 0;

    public int $revision = 1;

    public function state(Panel $panel, TenantRef $tenant): StateToken
    {
        $this->stateReads++;

        return StateToken::of('fixture', $panel->id(), 'incarnation', $this->revision, $panel->settings()->cacheGeneration(), 'fixture-fingerprint');
    }
}
