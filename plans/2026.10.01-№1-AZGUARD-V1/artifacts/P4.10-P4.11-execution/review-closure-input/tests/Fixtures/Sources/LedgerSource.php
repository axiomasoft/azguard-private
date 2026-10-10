<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Attributes\AsSource;
use AzGuard\Contracts\Sources\StoresGrants;
use Closure;

/**
 * A named writer whose clock comes from the current scope.
 */
#[AsSource('ledger')]
final class LedgerSource implements StoresGrants
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly ScopedClock $clock,
        private readonly array $config = [],
    ) {}

    public function id(): string
    {
        return 'ledger';
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback();
    }
}
