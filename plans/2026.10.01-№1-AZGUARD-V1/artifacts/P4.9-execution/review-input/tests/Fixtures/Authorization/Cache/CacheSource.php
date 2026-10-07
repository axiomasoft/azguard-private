<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization\Cache;

use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;

class CacheSource extends GeneratedSource
{
    public Volatility $reuse = Volatility::Request;

    public function volatility(): Volatility
    {
        return $this->reuse;
    }
}
