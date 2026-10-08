<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Kernel\Decision\StateToken;
use Closure;

/** A writer that stores wherever it is called, with no transaction of the pipeline. */
final class UnguardedWriter implements StoresGrants
{
    public function id(): string
    {
        return 'unguarded-writer';
    }

    public function apply(Change $change): ChangeResult
    {
        return ChangeResult::written(null, [], StateToken::of('unguarded', $change->panel, 'i', 1, 1, 'f'), 'c');
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback();
    }
}
