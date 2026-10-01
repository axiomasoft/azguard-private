<?php

// Source: anonymized production project

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestEnvironmentGuard;

/**
 * Basic TestCase. Overrides createApplication(), to drive away the guard
 * isolation on EVERY application launch - before even one is executed
 * query to the database or a file will be written.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * {@inheritdoc}
     *
     * Fails immediately if tests are connected to the main database/combat media disk
     * (RefreshDatabase executes migrate:fresh — this would destroy the data).
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        TestEnvironmentGuard::assertIsolatedTestDatabase($app);
        TestEnvironmentGuard::assertCanonicalTestDatabase($app, $this->canonicalTestDatabase());
        TestEnvironmentGuard::assertIsolatedTestMediaDisk($app);

        return $app;
    }

    /** Project-owned canonical serial DB resolver; never derive worker names here. */
    abstract protected function canonicalTestDatabase(): string;
}

/*
 * Safe use RefreshDatabase (in every test that touches the database):
 *
 *   use Illuminate\Foundation\Testing\RefreshDatabase;
 *
 *   final class OrderTest extends \Tests\TestCase
 *   {
 *       use RefreshDatabase; // safe: createApplication() has already guaranteed the DB `*_test`
 *   }
 *
 * Pest-option (tests/Pest.php):
 *
 *   pest()->extend(Tests\TestCase::class)
 *       ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
 *       ->in('Feature');
 *
 * Warranty policy:
 *   1. tests/bootstrap.php       — sets DB_DATABASE=*_test, MEDIA_DISK=media-test to autoload.
 *   2. TestCase::createApplication — serial guard checks resolved config with canonical DB.
 *   3. ParallelTestingIsolation::setUpTestDatabaseBeforeMigrating — exact worker DB after switch.
 *   4. RefreshDatabase           — migrate:fresh, only after both boundaries.
 */
