<?php

declare(strict_types=1);

namespace Acme\Consumer;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Changes\ChangePipe;
use Closure;

/** The consumer's change pipe: hands every change to the writer unchanged. */
final class ConsumerPipe implements ChangePipe
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        return $next($change);
    }
}
