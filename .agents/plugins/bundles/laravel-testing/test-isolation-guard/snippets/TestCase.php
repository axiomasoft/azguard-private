<?php

// Source: anonymized production project

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestEnvironmentGuard;

/**
 * Базовый TestCase. Переопределяет createApplication(), чтобы прогнать страж
 * изоляции на КАЖДОМ поднятии приложения — до того, как выполнится хоть один
 * запрос к БД или запишется файл.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * {@inheritdoc}
     *
     * Падает сразу, если тесты подключились к основной БД/боевому медиа-диску
     * (RefreshDatabase выполняет migrate:fresh — это уничтожило бы данные).
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
 * Безопасное применение RefreshDatabase (в каждом тесте, который трогает БД):
 *
 *   use Illuminate\Foundation\Testing\RefreshDatabase;
 *
 *   final class OrderTest extends \Tests\TestCase
 *   {
 *       use RefreshDatabase; // безопасно: createApplication() уже гарантировал БД `*_test`
 *   }
 *
 * Pest-вариант (tests/Pest.php):
 *
 *   pest()->extend(Tests\TestCase::class)
 *       ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
 *       ->in('Feature');
 *
 * Порядок гарантий:
 *   1. tests/bootstrap.php       — выставляет DB_DATABASE=*_test, MEDIA_DISK=media-test до autoload.
 *   2. TestCase::createApplication — serial guard сверяет resolved config с canonical DB.
 *   3. ParallelTestingIsolation::setUpTestDatabaseBeforeMigrating — exact worker DB after switch.
 *   4. RefreshDatabase           — migrate:fresh, только после обеих границ.
 */
