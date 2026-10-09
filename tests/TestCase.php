<?php

declare(strict_types=1);

namespace AzGuard\Tests;

use AzGuard\AzGuardServiceProvider;
use AzGuard\Filament\AzGuardFilamentServiceProvider;
use Closure;
use Illuminate\Database\Connection;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

class TestCase extends Orchestra
{
    /**
     * Every test starts from an empty database, as it does on SQLite :memory:. On PostgreSQL, MySQL and MariaDB the
     * schema of the previous test would otherwise survive and the next `Schema::create()` fails with "already exists".
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->app['db']->connection();

        if ($connection->getDriverName() !== 'sqlite') {
            $connection->getSchemaBuilder()->dropAllTables();
        }
    }

    /**
     * Closes every connection the test opened: a server keeps a connection per PDO until the application is
     * collected, and a full run otherwise exhausts it ("too many clients").
     */
    protected function tearDown(): void
    {
        $this->closingConnections(fn () => parent::tearDown());
    }

    /** A reloaded application (`bootFilament()`) leaves the connections of the previous one behind the same way. */
    protected function reloadApplication(): void
    {
        $this->closingConnections(fn () => parent::reloadApplication());
    }

    /**
     * Runs the teardown of the current application, then closes its connections. They are taken before and closed
     * after: its callbacks (migration rollbacks) still use them, and closing an SQLite :memory: connection early would
     * drop the database they read.
     *
     * @param  Closure(): void  $teardown
     */
    private function closingConnections(Closure $teardown): void
    {
        $open = isset($this->app) ? $this->app['db']->getConnections() : [];

        $teardown();

        foreach ($open as $connection) {
            if ($connection instanceof Connection) {
                $connection->disconnect();
            }
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            AzGuardServiceProvider::class,
            AzGuardFilamentServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.debug', true);

        $app['config']->set('database.default', 'testbench');
        $connection = $this->databaseConnectionConfig();

        if ($connection['driver'] !== 'sqlite' && ! str_ends_with((string) $connection['database'], '_test')) {
            throw new RuntimeException('Тестовая БД должна оканчиваться на _test; проверьте PGSQL_DATABASE/MYSQL_DATABASE.');
        }
        $app['config']->set('database.connections.testbench', $connection);
        $app['config']->set('database.connections.secondary', $connection);
    }

    /**
     * @return array<string, mixed>
     */
    protected function databaseConnectionConfig(): array
    {
        if (getenv('AUTHORITY_REPLICA_TEST')) {
            return ['driver' => 'pgsql', 'host' => '127.0.0.1',
                'port' => getenv('AUTHORITY_PRIMARY_PORT') ?: 25433,
                'database' => 'azguard_authority_test', 'username' => 'authority_test',
                'password' => 'authority_test', 'charset' => 'utf8', 'prefix' => ''];
        }

        return match (env('DB_CONNECTION', 'sqlite')) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('PGSQL_HOST', '127.0.0.1'),
                'port' => env('PGSQL_PORT', '5432'),
                'database' => env('PGSQL_DATABASE', 'azguard_test'),
                'username' => env('PGSQL_USERNAME', 'azguard'),
                'password' => env('PGSQL_PASSWORD', 'azguard'),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('MYSQL_HOST', '127.0.0.1'),
                'port' => env('MYSQL_PORT', '3306'),
                'database' => env('MYSQL_DATABASE', 'azguard_test'),
                'username' => env('MYSQL_USERNAME', 'azguard'),
                'password' => env('MYSQL_PASSWORD', 'azguard'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'mariadb' => [
                'driver' => 'mariadb',
                'host' => env('MARIADB_HOST', '127.0.0.1'),
                'port' => env('MARIADB_PORT', '3307'),
                'database' => env('MARIADB_DATABASE', 'azguard_test'),
                'username' => env('MARIADB_USERNAME', 'azguard'),
                'password' => env('MARIADB_PASSWORD', 'azguard'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };
    }
}
