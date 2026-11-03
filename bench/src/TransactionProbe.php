<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use Throwable;

/** Bench-only observation of failed transaction attempts inside a single operation. */
final class TransactionProbe
{
    private static bool $active = false;

    private static int $failed = 0;

    /** @var array{retries: int, deadlocks: int, timeouts: int, lock_conflicts: int, serialization_failures: int, other_failures: int} */
    private static array $counts = ['retries' => 0, 'deadlocks' => 0, 'timeouts' => 0, 'lock_conflicts' => 0, 'serialization_failures' => 0, 'other_failures' => 0];

    public static function reset(): void
    {
        self::$counts = ['retries' => 0, 'deadlocks' => 0, 'timeouts' => 0, 'lock_conflicts' => 0, 'serialization_failures' => 0, 'other_failures' => 0];
        self::$active = false;
    }

    public static function begin(): void
    {
        self::$active = true;
        self::$failed = 0;
    }

    public static function failed(Throwable $error): void
    {
        if (! self::$active) {
            return;
        }
        self::$failed++;
        $message = strtolower($error->getMessage());
        $code = (string) $error->getCode();
        $kind = match (true) {
            str_contains($message, 'deadlock'), $code === '40P01', str_contains($message, '1213') => 'deadlocks',
            str_contains($message, 'timeout'), str_contains($message, 'timed out'), str_contains($message, 'lock wait'), str_contains($message, '1205') => 'timeouts',
            str_contains($message, 'database is locked'), $code === '55P03' => 'lock_conflicts',
            $code === '40001' => 'serialization_failures',
            default => 'other_failures',
        };
        self::$counts[$kind]++;
    }

    public static function end(bool $success): void
    {
        self::$counts['retries'] += max(0, self::$failed - ($success ? 0 : 1));
        self::$active = false;
    }

    /** @return array{retries: int, deadlocks: int, timeouts: int, lock_conflicts: int, serialization_failures: int, other_failures: int} */
    public static function counts(): array
    {
        return self::$counts;
    }
}
