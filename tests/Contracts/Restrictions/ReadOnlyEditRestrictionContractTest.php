<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Restrictions;

use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Testing\Contracts\RestrictionContractTests;
use AzGuard\Tests\Fixtures\Contracts\ReadOnlyEditRestriction;
use AzGuard\Tests\TestCase;

final class ReadOnlyEditRestrictionContractTest extends TestCase
{
    use RestrictionContractTests;

    protected function azguardRestriction(): Restriction
    {
        return new ReadOnlyEditRestriction;
    }
}
