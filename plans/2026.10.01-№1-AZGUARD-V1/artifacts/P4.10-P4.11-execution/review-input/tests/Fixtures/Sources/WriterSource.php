<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Contracts\Sources\StoresGrants;
use Closure;

/**
 * An object writer. The recipe keeps this instance for every scope.
 */
final class WriterSource implements StoresGrants
{
    public function __construct(private readonly string $id) {}

    public function id(): string
    {
        return $this->id;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback();
    }
}
