<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Configuration;

/**
 * Boots a fresh application with configuration set before the package boots, for settings the provider reads in boot().
 */
trait BootsWithConfiguration
{
    /** @var array<string, mixed> */
    private array $bootConfiguration = [];

    /**
     * @param  array<string, mixed>  $config  configuration keys and values
     */
    protected function bootWith(array $config): void
    {
        $this->bootConfiguration = $config;

        $this->reloadApplication();
    }

    protected function defineEnvironment($app): void
    {
        foreach ($this->bootConfiguration as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
