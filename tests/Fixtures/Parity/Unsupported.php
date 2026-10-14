<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity;

/**
 * A path that cannot answer a case exactly, with its stable reason. It is never agreement: the case leaves the
 * equality count and the ledger records it against `unsupported.json`.
 */
final readonly class Unsupported
{
    public function __construct(public string $reason) {}

    public function label(): string
    {
        return 'unsupported('.$this->reason.')';
    }
}
