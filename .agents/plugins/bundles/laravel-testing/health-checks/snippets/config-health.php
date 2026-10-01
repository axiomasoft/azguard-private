<?php

// Source: anonymized production project

declare(strict_types=1);

use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\CacheHealthResultStore;

/*
 * config/health.php — package config spatie/laravel-health.
 *
 * Store, notifications and project sections for custom checks.
 * The list of checks itself is NOT registered here, but through Health::checks([...])
 * in bootstrap-provider (see block «BINDING» at the bottom of the file).
 */

return [
    /*
     * Where the results of the last run are stored. Endpoint readiness
     * returns exactly the saved result, and does not run checks for each request.
     */
    'result_stores' => [
        CacheHealthResultStore::class => [
            'store' => 'file',
        ],
        // Spatie\Health\ResultStores\EloquentHealthResultStore::class,
        // Spatie\Health\ResultStores\JsonFileHealthResultStore::class => ['disk' => 's3', 'path' => 'health.json'],
    ],

    /*
     * Notifications when checks fail. throttle protects against email storm.
     */
    'notifications' => [
        'enabled' => env('HEALTH_NOTIFICATIONS_ENABLED', false),

        'notifications' => [
            CheckFailedNotification::class => ['mail'], // or ['mail', 'slack']
        ],

        'notifiable' => Notifiable::class,

        'throttle_notifications_for_minutes' => 60,
        'throttle_notifications_key' => 'health:latestNotificationSentAt:',

        // true — send only when 'failed', warning ignore.
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
     * Response code readiness-endpoint when the check fails - reads it probe/balancer.
     */
    'json_results_failure_status' => env('HEALTH_JSON_FAILURE_STATUS', 503),

    /*
     * Secret for secure access to endpoint (header X-Secret-Token).
     */
    'secret_token' => env('HEALTH_SECRET_TOKEN'),

    /*
     * External monitoring: ping heartbeat-URL on success.
     */
    'oh_dear_endpoint' => [
        'enabled' => false,
        'always_send_fresh_results' => true,
        'secret' => env('OH_DEAR_HEALTH_CHECK_SECRET'),
        'url' => '/oh-dear-health-check-results',
    ],

    /*
     * Design sections for custom checks - thresholds/timeouts via env,
     * to transfer between environments without editing the code.
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
 * BINDING (lives in other project files - here for completeness).
 * =========================================================================
 *
 * --- app/Providers/AppServiceProvider::boot() — registration of checks ---
 *
 * Health::checks([
 *     DatabaseConnectionCheck::new(),
 *     CacheStoreCheck::new(),
 *     QueueConnectionCheck::new(),
 *     SchedulerHeartbeatCheck::new(),
 *     // parameterizable check - factory + unique name:
 *     DiskWriteCheck::forDisk('media', 'health/media')->name('disk_media_write'),
 *     DiskWriteCheck::forDisk('local', 'health/local')->name('disk_local_write'),
 *     ServiceTcpConnectionCheck::new(),
 *     // built-in package checks - take it as is, do not duplicate it with custom:
 *     UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(70),
 *     DebugModeCheck::new(),
 *     EnvironmentCheck::new(),
 * ]);
 *
 * --- routes/web.php — endpoint's liveness / readiness / dashboard ---
 *
 * Route::get('/health/live', SimpleHealthCheckController::class)->name('health.live');
 * Route::get('/health/ready', HealthCheckJsonResultsController::class)->name('health.ready');
 * Route::get('/health', HealthCheckResultsController::class)->name('health.dashboard'); // Blade-dashboard
 * // (for Filament — the package page is registered in the panel, a separate route is not needed)
 *
 * --- routes/console.php — run schedule + heartbeat scheduler ---
 *
 * Schedule::command('health:check')->everyMinute(); // run checks → save to store
 * Schedule::call(function (): void {
 *     cache()->forever('health:scheduler:last_heartbeat', now()->toIso8601String());
 * })->name('health-scheduler-heartbeat')->everyMinute();
 */
