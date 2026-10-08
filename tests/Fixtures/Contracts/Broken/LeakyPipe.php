<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;

/** A pipe that writes its row on a connection no transaction of the pipeline covers. */
final class LeakyPipe
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        app('db')->connection('secondary')->table('contract_leak')->insert(['note' => $change->type->value]);

        return $next($change);
    }
}
