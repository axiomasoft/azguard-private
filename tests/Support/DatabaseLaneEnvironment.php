<?php

declare(strict_types=1);

namespace AzGuard\Tests\Support;

use Dotenv\Dotenv;
use RuntimeException;

/** Optional local endpoints for the database test runner; explicit process environment always wins. */
final class DatabaseLaneEnvironment
{
    /** @param array<string, string> $environment
     * @return array<string, string>
     */
    public static function read(string $path, array $environment): array
    {
        if (! is_file($path)) {
            return [];
        }
        $values = Dotenv::parse((string) file_get_contents($path));
        $result = [];
        foreach (['PGSQL', 'MYSQL', 'MARIADB'] as $driver) {
            foreach (['PORT', 'DATABASE'] as $part) {
                $key = $driver.'_'.$part;

                if (array_key_exists($key, $environment) || ! isset($values[$key])) {
                    continue;
                }
                $value = $values[$key];
                $valid = $part === 'DATABASE'
                    ? is_string($value) && preg_match('/\A[a-zA-Z0-9_]+_test\z/', $value) === 1
                    : is_string($value) && ctype_digit($value) && (int) $value > 0 && (int) $value <= 65535;

                if (! $valid) {
                    throw new RuntimeException('Invalid '.$key.' in local database lane configuration.');
                }
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
