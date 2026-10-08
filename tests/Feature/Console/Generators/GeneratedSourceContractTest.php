<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Console\Generators;

use AzGuard\Contracts\Sources\Source;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Tests\Fixtures\Console\GeneratesComponents;
use AzGuard\Tests\TestCase;

/** The source `azguard:make:source` writes with every capability passes the contract suite of sources. */
final class GeneratedSourceContractTest extends TestCase
{
    use GeneratesComponents;
    use SourceContractTests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generate('azguard:make:source', ['name' => 'Ldap', '--panel' => 'Admin', '--grants' => true, '--permissions' => true, '--roles' => true, '--policies' => true]);
    }

    protected function azguardSource(): Source
    {
        $class = $this->generatedClass('Admin\Sources\LdapSource');

        return new $class;
    }
}
