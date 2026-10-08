<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Console;

/**
 * For a test case that runs a contract suite against a class a generator writes: the class is generated into a
 * throwaway application directory before each test and made again, from nothing, by the next one.
 */
trait GeneratesComponents
{
    private GeneratedApp $generated;

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function generate(string $command, array $arguments): void
    {
        $this->generated = GeneratedApp::in($this->app);
        mkdir($this->generated->path('app/Guards/Admin'), 0o755, true);
        $this->artisan($command, $arguments)->assertSuccessful();
    }

    protected function tearDown(): void
    {
        if (isset($this->generated)) {
            $this->generated->release();
        }
        parent::tearDown();
    }

    /** @return class-string */
    protected function generatedClass(string $relative): string
    {
        /** @var class-string $class */
        $class = $this->generated->class($relative);

        return $class;
    }
}
