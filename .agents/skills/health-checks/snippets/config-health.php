<?php

// Source: anonymized production project

declare(strict_types=1);

use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\CacheHealthResultStore;

/*
 * config/health.php — конфиг пакета spatie/laravel-health.
 *
 * Store, уведомления и проектные секции для кастомных проверок.
 * Сам список проверок регистрируется НЕ здесь, а через Health::checks([...])
 * в bootstrap-провайдере (см. блок «ОБВЯЗКА» внизу файла).
 */

return [
    /*
     * Где хранятся результаты последнего прогона. Endpoint readiness
     * отдаёт именно сохранённый результат, а не гоняет проверки на каждый запрос.
     */
    'result_stores' => [
        CacheHealthResultStore::class => [
            'store' => 'file',
        ],
        // Spatie\Health\ResultStores\EloquentHealthResultStore::class,
        // Spatie\Health\ResultStores\JsonFileHealthResultStore::class => ['disk' => 's3', 'path' => 'health.json'],
    ],

    /*
     * Уведомления при падении проверок. throttle защищает от шторма писем.
     */
    'notifications' => [
        'enabled' => env('HEALTH_NOTIFICATIONS_ENABLED', false),

        'notifications' => [
            CheckFailedNotification::class => ['mail'], // или ['mail', 'slack']
        ],

        'notifiable' => Notifiable::class,

        'throttle_notifications_for_minutes' => 60,
        'throttle_notifications_key' => 'health:latestNotificationSentAt:',

        // true — слать только при 'failed', warning игнорировать.
        'only_on_failure' => false,

        'mail' => [
            'to' => env('HEALTH_NOTIFICATIONS_MAIL_TO', (string) env('MAIL_FROM_ADDRESS', 'health@example.com')),
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'health@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Health'),
            ],
        ],

        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL', ''),
            'channel' => null,
        ],
    ],

    /*
     * Код ответа readiness-endpoint при упавшей проверке — его читает probe/балансировщик.
     */
    'json_results_failure_status' => env('HEALTH_JSON_FAILURE_STATUS', 503),

    /*
     * Секрет для защищённого доступа к endpoint (заголовок X-Secret-Token).
     */
    'secret_token' => env('HEALTH_SECRET_TOKEN'),

    /*
     * Внешний мониторинг: пинг heartbeat-URL при успешном прогоне.
     */
    'oh_dear_endpoint' => [
        'enabled' => false,
        'always_send_fresh_results' => true,
        'secret' => env('OH_DEAR_HEALTH_CHECK_SECRET'),
        'url' => '/oh-dear-health-check-results',
    ],

    /*
     * Проектные секции для кастомных проверок — пороги/таймауты через env,
     * чтобы переносить между окружениями без правки кода.
     */
    'scheduler' => [
        'heartbeat_url' => env('SCHEDULE_HEARTBEAT_URL'),
        'max_delay_minutes' => (int) env('HEALTH_SCHEDULER_MAX_DELAY_MINUTES', 2),
    ],

    'service' => [
        'host' => env('HEALTH_SERVICE_HOST', '127.0.0.1'),
        'port' => (int) env('HEALTH_SERVICE_PORT', 6379),
        'timeout_seconds' => (float) env('HEALTH_SERVICE_TIMEOUT_SECONDS', 2),
    ],
];

/*
 * =========================================================================
 * ОБВЯЗКА (живёт в других файлах проекта — здесь для полноты картины).
 * =========================================================================
 *
 * --- app/Providers/AppServiceProvider::boot() — регистрация проверок ---
 *
 * Health::checks([
 *     DatabaseConnectionCheck::new(),
 *     CacheStoreCheck::new(),
 *     QueueConnectionCheck::new(),
 *     SchedulerHeartbeatCheck::new(),
 *     // параметризуемая проверка — фабрика + уникальное имя:
 *     DiskWriteCheck::forDisk('media', 'health/media')->name('disk_media_write'),
 *     DiskWriteCheck::forDisk('local', 'health/local')->name('disk_local_write'),
 *     ServiceTcpConnectionCheck::new(),
 *     // встроенные проверки пакета — берём как есть, не дублируем кастомом:
 *     UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(70),
 *     DebugModeCheck::new(),
 *     EnvironmentCheck::new(),
 * ]);
 *
 * --- routes/web.php — endpoint'ы liveness / readiness / дашборд ---
 *
 * Route::get('/health/live', SimpleHealthCheckController::class)->name('health.live');
 * Route::get('/health/ready', HealthCheckJsonResultsController::class)->name('health.ready');
 * Route::get('/health', HealthCheckResultsController::class)->name('health.dashboard'); // Blade-дашборд
 * // (для Filament — регистрируется страница пакета в панели, отдельный роут не нужен)
 *
 * --- routes/console.php — расписание прогона + heartbeat шедулера ---
 *
 * Schedule::command('health:check')->everyMinute(); // прогон проверок → сохранение в store
 * Schedule::call(function (): void {
 *     cache()->forever('health:scheduler:last_heartbeat', now()->toIso8601String());
 * })->name('health-scheduler-heartbeat')->everyMinute();
 */
