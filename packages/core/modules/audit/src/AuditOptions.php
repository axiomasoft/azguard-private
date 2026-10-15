<?php

declare(strict_types=1);

namespace AzGuard\Audit;

use AzGuard\Attributes\Api;
use InvalidArgumentException;

/**
 * Typed options the audit plugin contributes to a panel: read them with `Panel::options(AuditOptions::class)`.
 */
#[Api(since: '1.0')]
final readonly class AuditOptions
{
    /** @throws InvalidArgumentException when the retention is shorter than one day */
    public function __construct(public int $retentionDays)
    {
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('retentionDays must be at least 1.');
        }
    }
}
