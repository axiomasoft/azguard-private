<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;
use RuntimeException;

/** A pipe that swallows the change: it never reaches the writer. */
final class SwallowingPipe
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        throw new RuntimeException('The change was dropped.');
    }
}
