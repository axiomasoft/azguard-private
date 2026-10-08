<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;

/**
 * @internal The last pipe of a suite that cancels the change after the pipe of the author has run.
 */
final class CancellingPipe
{
    public function handle(Change $change, Closure $next): ChangeResult
    {
        $change->cancel('contract suite');
    }
}
