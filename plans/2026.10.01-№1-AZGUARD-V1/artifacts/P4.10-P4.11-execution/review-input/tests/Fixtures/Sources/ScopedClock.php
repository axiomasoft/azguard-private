<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources;

/**
 * A scoped dependency: a new clock for each request or job.
 */
final class ScopedClock
{
    public string $token;

    public function __construct()
    {
        $this->token = bin2hex(random_bytes(8));
    }
}
