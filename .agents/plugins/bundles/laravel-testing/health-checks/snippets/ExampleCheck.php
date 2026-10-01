<?php

// Source: anonymized production project

declare(strict_types=1);

namespace App\Health\Checks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Custom template spatie/laravel-health checks.
 *
 * Demonstrates all the key techniques:
 *  - run(): Result — no exceptions outward;
 *  - active resource check (real entry/read), rather than reading the config;
 *  - configuration via config(...) with defaults;
 *  - diagnostics in ->meta([...]);
 *  - parameterization via private constructor + static factory.
 *
 * In a real project, divided into several narrow classes
 * (DiskWriteCheck, CacheStoreCheck, ServiceTcpConnectionCheck, ...).
 * Here they are in one file only for clarity of the pattern.
 */
final class ExampleCheck extends Check
{
    /**
     * Private constructor + factory - when the check is run
     * for multiple purposes (for example, for different disks).
     */
    public function __construct(
        private readonly string $disk = 'local',
        private readonly string $directory = 'health',
    ) {}

    public static function forDisk(string $disk, string $directory = 'health'): self
    {
        return new self(disk: $disk, directory: $directory);
    }

    /**
     * run() ALWAYS returns Result. Any exception to I/O/networks/drivers
     * we catch and turn into ->failed(), otherwise the entire check run crashes.
     */
    public function run(): Result
    {
        // 1) Active disk check: real write → read → deletion.
        $filename = $this->directory.'/'.Str::uuid()->toString().'.txt';
        $payload = 'ok:'.now()->toIso8601String();

        try {
            Storage::disk($this->disk)->put($filename, $payload);
            $read = Storage::disk($this->disk)->get($filename);
            Storage::disk($this->disk)->delete($filename); // always remove temporary artifact
        } catch (Throwable $exception) {
            return Result::make()->failed($exception->getMessage());
        }

        if ($read !== $payload) {
            return Result::make()->failed("Disk [{$this->disk}] returned unexpected data.");
        }

        // 2) Indirect check via heartbeat: age of the label against the threshold from the config.
        $maxDelayMinutes = (int) config('health.scheduler.max_delay_minutes', default: 2);
        $timestamp = Cache::get('health:scheduler:last_heartbeat');

        if (! is_string($timestamp) || $timestamp === '') {
            return Result::make()->failed('Missing heartbeat scheduler.');
        }

        $lastHeartbeatAt = CarbonImmutable::parse($timestamp);
        $minutesSince = $lastHeartbeatAt->diffInMinutes(now());

        if ($minutesSince > $maxDelayMinutes) {
            return Result::make()->failed('Heartbeat scheduler is outdated.');
        }

        // 3) Verification TCP-external service port: real connection with timeout.
        $host = (string) config('health.service.host', default: '127.0.0.1');
        $port = (int) config('health.service.port', default: 6379);
        $timeout = (float) config('health.service.timeout_seconds', default: 2.0);

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (! is_resource($socket)) {
            return Result::make()->failed("TCP-service port unavailable: {$errno} {$errstr}");
        }

        fclose($socket);

        // Success: short message + diagnostics in meta.
        return Result::make()
            ->meta([
                'disk' => $this->disk,
                'service' => "{$host}:{$port}",
                'minutes_since_heartbeat' => $minutesSince,
            ])
            ->ok('All resources are available.');
    }
}
