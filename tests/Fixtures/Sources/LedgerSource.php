<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

use AzGuard\Attributes\AsSource;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Sources\StoresGrants;
use Closure;
use RuntimeException;

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

    public function apply(Change $change): ChangeResult
    {
        throw new RuntimeException('This fixture writer stores nothing.');
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback();
    }
}
