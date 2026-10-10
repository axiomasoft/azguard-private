<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Orders;

final readonly class Clock
{
    public function __construct(public bool $open = true) {}
}
