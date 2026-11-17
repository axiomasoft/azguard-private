<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Probes;

use PHPUnit\Framework\Assert;
use ReflectionMethod;

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
        return (new ReflectionMethod(Assert::class, $name))->invokeArgs(null, $arguments);
    }

    public function run(string $method): void
    {
        (new ReflectionMethod($this, $method))->invoke($this);
    }
}
