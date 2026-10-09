<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use RuntimeException;

/** Refuses to seed a database that is not disposable: the bench drops and recreates every table (as chatom bench). */
final class DisposableDatabase
{
    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', 'postgres', 'mysql', 'mariadb'];

    /** @param array<string, mixed> $config */
    public static function assertSafe(array $config): void
    {
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';
        $database = is_string($config['database'] ?? null) ? $config['database'] : '';

        if ($driver === 'sqlite') {
            if ($database === ':memory:' || str_ends_with($database, '_bench.sqlite')) {
                return;
            }

            throw new RuntimeException("The bench refuses the SQLite file [{$database}]: its name must end with _bench.sqlite.");
        }

        if (! str_ends_with($database, '_bench') && ! str_ends_with($database, '_test')) {
            throw new RuntimeException("The bench refuses the database [{$database}]: its name must end with _bench or _test.");
        }

        if (! in_array($config['host'] ?? null, self::LOCAL_HOSTS, true)) {
            throw new RuntimeException('The bench refuses a database host that is not local.');
        }
    }
}
