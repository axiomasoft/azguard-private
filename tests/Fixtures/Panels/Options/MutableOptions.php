<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels\Options;

/** Not readonly: refused as an option object. */
final class MutableOptions
{
    public function __construct(public int $limit = 10) {}
}
