<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * Register from a project ParallelTesting provider:
 *
 * ParallelTesting::setUpProcess(fn ($token) =>
 *     ParallelTestingIsolation::configureProcessDisks($app, $token, base_path('storage/testing'), ['media-test']));
 * ParallelTesting::setUpTestDatabaseBeforeMigrating(fn ($database, $token) =>
 *     ParallelTestingIsolation::assertExactSelectedDatabase($app, $database, $token, $resolver));
 *
 * The callback supplies observed database/token.  No suffix or guessed worker name is accepted.
 */
final class ParallelTestingIsolation
{
    public static function assertExactSelectedDatabase(
        Application $app,
        ?string $selectedDatabase,
        int|string|null $token,
        callable $expectedDatabase,
    ): void {
        if ($selectedDatabase === null || $selectedDatabase === '' || $token === null || $token === '') {
            throw new RuntimeException('FRAMEWORK_LIFECYCLE_CONFLICT: callback did not provide selected database and token before migration.');
        }
        $expected = $expectedDatabase($token);
        $default = (string) $app->make('config')->get('database.default');
        $resolved = $app->make('config')->get("database.connections.{$default}.database");
        if (! is_string($expected) || $expected === '' || ! is_string($resolved)
            || $resolved !== $selectedDatabase || $resolved !== $expected) {
            throw new RuntimeException('FRAMEWORK_LIFECYCLE_CONFLICT: active worker database is not the exact framework-selected database.');
        }
    }

    /** Configure only declared app-owned local disks under one process-owned root. */
    public static function configureProcessDisks(Application $app, int|string $token, string $testingBase, array $diskNames): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', (string) $token)) {
            throw new RuntimeException('FRAMEWORK_LIFECYCLE_CONFLICT: unsafe parallel process token.');
        }
        if (! is_dir($testingBase) && ! mkdir($testingBase, 0700, true) && ! is_dir($testingBase)) {
            throw new RuntimeException('FRAMEWORK_LIFECYCLE_CONFLICT: cannot create testing base.');
        }
        $base = realpath($testingBase);
        $root = $base === false ? false : $base.DIRECTORY_SEPARATOR.'worker-'.(string) $token;
        if ($base === false || $root === false || str_contains($root, '..')) {
            throw new RuntimeException('FRAMEWORK_LIFECYCLE_CONFLICT: invalid process root.');
        }
        foreach ($diskNames as $disk) {
            $config = $app->make('config')->get("filesystems.disks.{$disk}");
            if (! is_array($config) || ($config['driver'] ?? null) !== 'local') {
                throw new RuntimeException("FRAMEWORK_LIFECYCLE_CONFLICT: writable disk {$disk} is not local.");
            }
            $app->make('config')->set("filesystems.disks.{$disk}.root", $root.DIRECTORY_SEPARATOR.$disk);
        }
        return $root;
    }

    /** Cleanup only a normalized owned root; a base/symlink escape is a hard refusal. */
    public static function cleanupOwnedRoot(string $root, string $testingBase): void
    {
        $base = realpath($testingBase);
        $resolved = realpath($root);
        if ($base === false || $resolved === false || is_link($root)
            || ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR.'worker-')) {
            throw new RuntimeException('FRAMEWORK_LIFECYCLE_CONFLICT: cleanup target is outside owned process root.');
        }
        self::removeTree($resolved);
    }

    private static function removeTree(string $path): void
    {
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('FRAMEWORK_LIFECYCLE_CONFLICT: symlink in owned process root.');
            }
            $entry->isDir() ? self::removeTree($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
