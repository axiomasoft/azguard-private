<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;

/** A pipe that writes its own row before it hands the change to the writer. */
final class WritingPipe
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        app('db')->table('contract_probe')->insert(['note' => $change->type->value]);

        return $next($change);
    }
}
