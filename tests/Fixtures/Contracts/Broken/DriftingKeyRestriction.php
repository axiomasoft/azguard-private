<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

/** A restriction whose key is a new one at every call. */
final class DriftingKeyRestriction extends BaseRestriction
{
    private int $calls = 0;

    public function key(): string
    {
        return 'drift_'.$this->calls++;
    }
}
