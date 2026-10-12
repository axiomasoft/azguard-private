<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Changes\ChangePipe;
use Closure;

/** A pipe that looks at the change and hands it to the writer. */
class PassThroughPipe implements ChangePipe
{
    public static int $seen = 0;

    public function handle(Change $change, Closure $next): ChangeResult
    {
        self::$seen++;

        return $next($change);
    }
}
