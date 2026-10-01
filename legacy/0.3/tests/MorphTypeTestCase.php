<?php

declare(strict_types=1);

namespace AzGuard\Tests;

use Orchestra\Testbench\Attributes\ResetRefreshDatabaseState;

/**
 * Boots the suite with ULID morph keys so the az_guard tables are migrated
 * with ulidMorphs (config-driven morph_type).
 */
#[ResetRefreshDatabaseState]
class MorphTypeTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('az-guard.column_names.morph_type', 'ulid');
    }
}
