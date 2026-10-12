<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels\Options;

/** A readonly option object of plain values. */
final readonly class LimitOptions
{
    public function __construct(public int $limit = 10, public string $label = 'plain') {}
}
