<?php

declare(strict_types=1);

namespace AzGuard\Tests\Support;

use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The database a test process uses on a server-backed driver. A ParaTest worker (`TEST_TOKEN`) gets its own database,
 * `azguard_<token>_test` next to `azguard_test`, so workers never drop each other's tables; the name keeps the
 * `_test` suffix the isolation guard of `TestCase` requires.
 */
final class TestDatabase
{
    public static function name(string $base): string
    {
        $token = getenv('TEST_TOKEN');

        if ($token === false || $token === '' || ! str_ends_with($base, '_test')) {
            return $base;
        }

        return substr($base, 0, -strlen('_test')).'_'.$token.'_test';
    }

    /**
     * A worker whose database does not exist fails on its first query with an opaque connection error; this names the
     * database and the command that creates it (`docker/initdb` does it for the compose stand, for N workers).
     */
    public static function unreachable(string $database, QueryException $previous): RuntimeException
    {
        $token = getenv('TEST_TOKEN');

        if ($token === false || $token === '' || ! str_contains($previous->getMessage(), $database)) {
            return new RuntimeException($previous->getMessage(), previous: $previous);
        }

        return new RuntimeException(
            "Test database `{$database}` of ParaTest worker {$token} is unreachable. Create one database per worker "
            ."(azguard_1_test ... azguard_N_test, N >= --processes), e.g. on PostgreSQL: CREATE DATABASE {$database} OWNER <user>; "
            .'on MySQL/MariaDB: CREATE DATABASE `'.$database.'`; GRANT ALL ON `'.$database.'`.* TO <user>. '
            .'The compose stand creates 16 on first start (TEST_DATABASE_WORKERS). Cause: '.$previous->getMessage(),
            previous: $previous,
        );
    }
}
