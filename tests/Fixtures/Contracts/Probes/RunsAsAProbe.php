<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use PHPUnit\Framework\Assert;

/**
 * Lets a contract suite run outside a test case: its assertions are the static ones of PHPUnit, so a broken
 * implementation raises the same failure it would raise in the test case of its author, and the test of the suites
 * can catch it.
 */
trait RunsAsAProbe
{
    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        return Assert::$name(...$arguments);
    }

    public function run(string $method): void
    {
        $this->{$method}();
    }
}
