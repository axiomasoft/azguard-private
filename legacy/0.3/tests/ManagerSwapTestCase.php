<?php

declare(strict_types=1);

namespace AzGuard\Tests;

use AzGuard\Tests\Stubs\SwapTestManager;

/**
 * Boots the package with a config-overridden manager class, set BEFORE the
 * service provider registers (the way a real integrator swaps it in
 * config/az-guard.php). Proves the F5 'manager' seam is honoured end-to-end.
 */
class ManagerSwapTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('az-guard.manager', SwapTestManager::class);
    }
}
