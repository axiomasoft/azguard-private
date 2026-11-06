<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Operations;

/** Override before TestCase::setUpTraits(), which may drop tables on server drivers. */
trait RunbookEnvironment
{
    protected function databaseConnectionConfig(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('azguard.storages.default.connection', 'testbench');
        $app['config']->set('azguard.catalog.build_id', 'production-runbook-test');
        $app['config']->set('app.debug', false);
    }
}
