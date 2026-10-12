<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Changes;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;

/**
 * A pipe a change of grants passes through: it may replace `until` and `fields` or cancel by throwing, must call
 * `$next` exactly once and return the result `$next` returned.
 *
 * @phpstan-type PipeClosure Closure(Change, Closure(Change): ChangeResult): ChangeResult
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ChangePipe
{
    /** @param Closure(Change): ChangeResult $next */
    public function handle(Change $change, Closure $next): ChangeResult;
}
