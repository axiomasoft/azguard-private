<?php

declare(strict_types=1);

namespace AzGuard\Tests;

use AzGuard\Context\AzGuardContextServiceProvider;

/**
 * TestCase с подключённым пакетом azguard/context.
 * Используется в тестах, которым нужен AuthorizationContextManager,
 * ContextPermissionLayer и миграция az_guard_context_roles.
 */
class ContextTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AzGuardContextServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(
            __DIR__.'/../packages/context/database/migrations',
        );
    }
}
