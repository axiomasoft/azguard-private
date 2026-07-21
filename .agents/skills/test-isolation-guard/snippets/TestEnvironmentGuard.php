<?php

// Source: anonymized production project

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * Страж изоляции тестового окружения.
 *
 * Вызывается из TestCase::createApplication() сразу после поднятия приложения и
 * АВАРИЙНО прерывает прогон, если активная БД или медиа-диск — не тестовые.
 * Это последний рубеж: даже если phpunit.xml/bootstrap.php сломаны или подменён .env,
 * тест упадёт ДО первого запроса и не запустит migrate:fresh по боевой/dev базе.
 */
final class TestEnvironmentGuard
{
    /**
     * БД активного подключения обязана заканчиваться на `_test`.
     *
     * RefreshDatabase выполняет migrate:fresh — запуск по основной БД уничтожит данные.
     */
    public static function assertIsolatedTestDatabase(Application $app): void
    {
        $config = $app->make('config');

        $default = (string) $config->get('database.default');
        $database = $config->get("database.connections.{$default}.database");

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(sprintf(
                "ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: активная БД не является тестовой.\n".
                    "  connection: [%s], database: [%s]\n".
                    "Тесты должны работать только с БД, имя которой заканчивается на `_test`, ".
                    "потому что RefreshDatabase вызывает migrate:fresh и СОТРЁТ данные основной БД.\n".
                    "Как починить:\n".
                    "  1. Убедитесь, что phpunit.xml содержит APP_ENV=testing и DB_DATABASE=<app>_test.\n".
                    "  2. Проверьте tests/bootstrap.php — он должен выставлять DB_DATABASE до vendor/autoload.\n".
                    "  3. Создайте тестовую БД (например app_test) тем же OWNER, что и DB_USERNAME в .env.",
                $default,
                is_string($database) ? $database : (string) json_encode($database)
            ));
        }
    }

    /**
     * Активный медиа-диск обязан быть тестовым (`media-test`).
     *
     * Иначе тесты пишут загруженные файлы в боевой/dev стор.
     */
    public static function assertIsolatedTestMediaDisk(Application $app): void
    {
        $config = $app->make('config');

        $mediaDisk = (string) $config->get('media-library.disk_name');
        $mediaDiskConfig = $config->get("filesystems.disks.{$mediaDisk}");

        if ($mediaDisk !== 'media-test' || ! is_array($mediaDiskConfig)) {
            throw new RuntimeException(sprintf(
                "ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: медиа-диск не является тестовым.\n".
                    "  media-library.disk_name: [%s]\n".
                    "Тесты должны использовать диск `media-test`, чтобы не писать файлы в боевой стор.\n".
                    "Как починить:\n".
                    "  1. В phpunit.xml/tests/bootstrap.php выставьте MEDIA_DISK=media-test.\n".
                    "  2. Опишите диск filesystems.disks.media-test (локальный, изолированный root).",
                $mediaDisk
            ));
        }
    }
}
