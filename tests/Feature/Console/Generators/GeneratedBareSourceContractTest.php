<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Console\Generators;

use AzGuard\Contracts\Sources\Source;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Tests\Fixtures\Console\GeneratesComponents;
use AzGuard\Tests\TestCase;

/** The source `azguard:make:source` writes with no capability, in what panels share, passes the contract suite too. */
final class GeneratedBareSourceContractTest extends TestCase
{
    use GeneratesComponents;
    use SourceContractTests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generate('azguard:make:source', ['name' => 'Plain', '--shared' => true]);
    }

    protected function azguardSource(): Source
    {
        $class = $this->generatedClass('Shared\Sources\PlainSource');

        return new $class;
    }
}
