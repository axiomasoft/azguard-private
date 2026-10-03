<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use Closure;

/**
 * The writer of a panel. A panel has at most one.
 *
 * @spi
 */
interface StoresGrants extends Source
{
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
