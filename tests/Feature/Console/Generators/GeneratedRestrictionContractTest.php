<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Console\Generators;

use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Testing\Contracts\RestrictionContractTests;
use AzGuard\Tests\Fixtures\Console\GeneratesComponents;
use AzGuard\Tests\TestCase;

/** The restriction `azguard:make:restriction` writes passes the contract suite of restrictions. */
final class GeneratedRestrictionContractTest extends TestCase
{
    use GeneratesComponents;
    use RestrictionContractTests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generate('azguard:make:restriction', ['name' => 'AccountLocked', '--panel' => 'Admin']);
    }

    protected function azguardRestriction(): Restriction
    {
        $class = $this->generatedClass('Admin\Restrictions\AccountLockedRestriction');

        return new $class;
    }
}
