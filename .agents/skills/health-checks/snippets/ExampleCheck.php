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
 * Шаблон кастомной spatie/laravel-health проверки.
 *
 * Демонстрирует все ключевые приёмы:
 *  - run(): Result — никаких исключений наружу;
 *  - активная проверка ресурса (реальная запись/чтение), а не чтение конфига;
 *  - конфигурация через config(...) с дефолтами;
 *  - диагностика в ->meta([...]);
 *  - параметризация через приватный конструктор + статическую фабрику.
 *
 * В реальном проекте раздели на несколько узких классов
 * (DiskWriteCheck, CacheStoreCheck, ServiceTcpConnectionCheck, ...).
 * Здесь они в одном файле только для наглядности паттерна.
 */
final class ExampleCheck extends Check
{
    /**
     * Приватный конструктор + фабрика — когда проверку запускают
     * для нескольких целей (например, для разных дисков).
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
     * run() ВСЕГДА возвращает Result. Любое исключение из I/O/сети/драйвера
     * ловим и превращаем в ->failed(), иначе падает весь прогон проверок.
     */
    public function run(): Result
    {
        // 1) Активная проверка диска: реальная запись → чтение → удаление.
        $filename = $this->directory.'/'.Str::uuid()->toString().'.txt';
        $payload = 'ok:'.now()->toIso8601String();

        try {
            Storage::disk($this->disk)->put($filename, $payload);
            $read = Storage::disk($this->disk)->get($filename);
            Storage::disk($this->disk)->delete($filename); // временный артефакт убираем всегда
        } catch (Throwable $exception) {
            return Result::make()->failed($exception->getMessage());
        }

        if ($read !== $payload) {
            return Result::make()->failed("Диск [{$this->disk}] вернул неожиданные данные.");
        }

        // 2) Косвенная проверка через heartbeat: возраст метки против порога из конфига.
        $maxDelayMinutes = (int) config('health.scheduler.max_delay_minutes', default: 2);
        $timestamp = Cache::get('health:scheduler:last_heartbeat');

        if (! is_string($timestamp) || $timestamp === '') {
            return Result::make()->failed('Отсутствует heartbeat шедулера.');
        }

        $lastHeartbeatAt = CarbonImmutable::parse($timestamp);
        $minutesSince = $lastHeartbeatAt->diffInMinutes(now());

        if ($minutesSince > $maxDelayMinutes) {
            return Result::make()->failed('Heartbeat шедулера устарел.');
        }

        // 3) Проверка TCP-порта внешнего сервиса: реальное соединение с таймаутом.
        $host = (string) config('health.service.host', default: '127.0.0.1');
        $port = (int) config('health.service.port', default: 6379);
        $timeout = (float) config('health.service.timeout_seconds', default: 2.0);

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (! is_resource($socket)) {
            return Result::make()->failed("TCP-порт сервиса недоступен: {$errno} {$errstr}");
        }

        fclose($socket);

        // Успех: короткое сообщение + диагностика в meta.
        return Result::make()
            ->meta([
                'disk' => $this->disk,
                'service' => "{$host}:{$port}",
                'minutes_since_heartbeat' => $minutesSince,
            ])
            ->ok('Все ресурсы доступны.');
    }
}
