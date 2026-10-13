<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Consumer;

use Acme\Consumer\ConsumerRestriction;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Testing\Contracts\RestrictionContractTests;
use AzGuard\Tests\TestCase;

require_once dirname(__DIR__, 2).'/Fixtures/Modules/Consumer/autoload.php';

/** I8: the restriction of a package outside `AzGuard\` passes the restriction suite. */
final class ConsumerRestrictionContractTest extends TestCase
{
    use RestrictionContractTests;

    protected function azguardRestriction(): Restriction
    {
        return new ConsumerRestriction;
    }
}
