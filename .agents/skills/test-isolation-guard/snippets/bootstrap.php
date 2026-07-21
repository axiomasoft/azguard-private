<?php

// Source: anonymized production project

declare(strict_types=1);

/**
 * PHPUnit подключает этот bootstrap ПОСЛЕ применения <php><server>/<env> из phpunit.xml,
 * но ДО vendor/autoload.php. Здесь дублируется изоляция окружения, чтобы Laravel
 * никогда не увидел боевые/dev DB_* из $_SERVER, даже если порядок инструментов
 * (CI, IDE-раннер, локальный .env) изменится или сломается phpunit.xml.
 *
 * Ключевой инвариант: к моменту загрузки фреймворка имя БД заканчивается на `_test`,
 * а медиа-диск — тестовый. Любой реальный прогон с боевыми значениями исключён.
 *
 * @see https://github.com/laravel/framework/blob/master/src/Illuminate/Foundation/Testing/RefreshDatabase.php
 */
$isolate = static function (): void {
    $pairs = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        // Имя обязано заканчиваться на `_test` — это и проверяет TestEnvironmentGuard.
        'DB_DATABASE' => 'app_test',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        // Тестовый медиа-диск: запись файлов идёт в изолированный каталог, не в боевой.
        'MEDIA_DISK' => 'media-test',
    ];

    foreach ($pairs as $key => $value) {
        // Перезаписываем все три источника, которые читает Laravel env():
        // $_SERVER имеет приоритет над $_ENV, putenv() закрывает getenv().
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
};

$isolate();

require dirname(__DIR__).'/vendor/autoload.php';
