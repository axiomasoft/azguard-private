<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;

/**
 * The writer of a panel. A panel has at most one.
 *
 * @spi
 */
interface StoresGrants extends Source
{
    /**
     * Applies one change that the change pipeline validated, inside the active mutation of the panel. The writer reads
     * the stored grant under the lock, compares and writes; a repeat without a difference is `Unchanged`.
     */
    public function apply(Change $change): ChangeResult;

    /**
     * Runs the callback inside the transaction of the source's connection.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function transaction(Closure $callback): mixed;
}
