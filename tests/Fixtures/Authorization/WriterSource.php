<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Contracts\Sources\StoresGrants;
use Closure;
use RuntimeException;

final class WriterSource extends GeneratedSource implements StoresGrants
{
    public function transaction(Closure $callback): mixed
    {
        throw new RuntimeException('Authorization must never write.');
    }
}
