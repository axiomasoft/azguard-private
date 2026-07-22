<?php

declare(strict_types=1);

namespace AzGuard\Tests;

use AzGuard\AzGuardServiceProvider;
use AzGuard\Tests\Stubs\TestGuardPanelProvider;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AzGuardServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.debug', true);

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', $this->databaseConnectionConfig());

        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('az-guard.panels', [
            TestGuardPanelProvider::class,
        ]);

        $app['config']->set('az-guard.cache.store', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function createUserWithDirectGrant(string $permission, string $panelId): UserWithDirectGrants
    {
        $user = UserWithDirectGrants::factory()->create();
        $user->directGrants()->create([
            'panel_id' => $panelId,
            'permission_key' => $permission,
            'expires_at' => null,
        ]);

        return $user;
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
