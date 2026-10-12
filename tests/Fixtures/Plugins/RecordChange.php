<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Changes\ChangePipe;
use Closure;

/**
 * A change pipe the audit plugin adds to a panel.
 */
final class RecordChange implements ChangePipe
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        return $next($change);
    }
}
