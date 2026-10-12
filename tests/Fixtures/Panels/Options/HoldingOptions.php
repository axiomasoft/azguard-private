<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels\Options;

/** A readonly option object that holds whatever the test gives it. */
final readonly class HoldingOptions
{
    public function __construct(public mixed $held) {}
}
