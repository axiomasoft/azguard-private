<?php

declare(strict_types=1);

namespace AzGuard\Tests;

use AzGuard\AzGuardServiceProvider;
use AzGuard\Filament\AzGuardFilamentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

class TestCase extends Orchestra
{
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
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };
    }
}
