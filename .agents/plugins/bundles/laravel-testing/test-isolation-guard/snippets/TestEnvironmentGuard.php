<?php

// Source: anonymized production project

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * Test environment isolation guard.
 *
 * Called from TestCase::createApplication() immediately after raising the application and
 * Aborts the run if the active database or media disk is not a test one.
 * This is the final frontier: even if phpunit.xml/bootstrap.php are broken or replaced.env,
 * test will fail BEFORE the first request and will not run migrate:fresh on combat/dev database.
 */
final class TestEnvironmentGuard
{
    /** Exact serial boundary. Worker DB names belong only to the parallel callback. */
    public static function assertCanonicalTestDatabase(Application $app, string $expected): void
    {
        $config = $app->make('config');
        $default = (string) $config->get('database.default');
        $database = $config->get("database.connections.{$default}.database");

        if (! is_string($database) || $expected === '' || $database !== $expected) {
            throw new RuntimeException(sprintf(
                "FRAMEWORK_LIFECYCLE_CONFLICT: serial DB differs from the project canonical resolver.\n".
                    "  connection: [%s], observed: [%s]\n".
                    "Do not derive a parallel worker DB in createApplication(); keep the canonical resolver " .
                    "and assert the framework-selected DB in setUpTestDatabaseBeforeMigrating().",
                $default,
                is_string($database) ? $database : (string) json_encode($database)
            ));
        }
    }

    /**
     * The active connection database must end with `_test`.
     *
     * RefreshDatabase executes migrate:fresh — running on the main database will destroy the data.
     */
    public static function assertIsolatedTestDatabase(Application $app): void
    {
        $config = $app->make('config');

        $default = (string) $config->get('database.default');
        $database = $config->get("database.connections.{$default}.database");

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(sprintf(
                "TEST ISOLATION BROKEN: the active database is not a test database.\n".
                    "  connection: [%s], database: [%s]\n".
                    "Tests should only work against databases whose name ends with `_test`, ".
                    "because RefreshDatabase calls migrate:fresh and WILL ERASE the main database data.\n".
                    "How to fix:\n".
                    "  1. Make sure that phpunit.xml contains APP_ENV=testing and DB_DATABASE=<app>_test.\n".
                    "  2. Check tests/bootstrap.php — it should set DB_DATABASE to vendor/autoload.\n".
                    "  3. Create a test database (for example app_test) the same OWNER, as DB_USERNAME in .env.",
                $default,
                is_string($database) ? $database : (string) json_encode($database)
            ));
        }
    }

    /**
     * The active media disk must be a test disk (`media-test`).
     *
     * Otherwise, the tests write the downloaded files to the combat/dev side.
     */
    public static function assertIsolatedTestMediaDisk(Application $app): void
    {
        $config = $app->make('config');

        $mediaDisk = (string) $config->get('media-library.disk_name');
        $mediaDiskConfig = $config->get("filesystems.disks.{$mediaDisk}");

        if ($mediaDisk !== 'media-test' || ! is_array($mediaDiskConfig)) {
            throw new RuntimeException(sprintf(
                "TEST ISOLATION BROKEN: The media disk is not a test disk.\n".
                    "  media-library.disk_name: [%s]\n".
                    "Tests must use disk `media-test`, so as not to write files to the combat side.\n".
                    "How to fix:\n".
                    "  1. B phpunit.xml/tests/bootstrap.php set MEDIA_DISK=media-test.\n".
                    "  2. Describe the disk filesystems.disks.media-test (local, isolated root).",
                $mediaDisk
            ));
        }
    }
}
