<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;

/** Has the `handle()` of a pipe but does not implement `ChangePipe`: the slot no longer accepts it by duck typing. */
final class HandleOnlyPipe
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        return $next($change);
    }
}
