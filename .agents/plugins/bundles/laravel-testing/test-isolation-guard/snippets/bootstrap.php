<?php

// Source: anonymized production project

declare(strict_types=1);

/**
 * PHPUnit connects this bootstrap AFTER application <php><server>/<env> from phpunit.xml,
 * but BEFORE vendor/autoload.php. This duplicates the environment isolation to Laravel
 * never saw combat/dev DB_* from $_SERVER, even if the order of the tools
 * (CI, IDE-runner, local .env) will change or break phpunit.xml.
 *
 * Key invariant: by the time the framework is loaded, the database name ends with `_test`,
 * and the media disk is a test disk. Any real run with combat values ​​is excluded.
 *
 * @see https://github.com/laravel/framework/blob/master/src/Illuminate/Foundation/Testing/RefreshDatabase.php
 */
$isolate = static function (): void {
    $pairs = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        // The name must end with `_test` — checks this TestEnvironmentGuard.
        'DB_DATABASE' => 'app_test',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        // Test media disk: files are written to an isolated directory, not to a combat directory.
        'MEDIA_DISK' => 'media-test',
    ];

    foreach ($pairs as $key => $value) {
        // Rewrite all three sources that are read Laravel env():
        // $_SERVER takes precedence over $_ENV, putenv() closes getenv().
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
};

$isolate();

require dirname(__DIR__).'/vendor/autoload.php';
