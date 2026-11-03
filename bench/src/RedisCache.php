<?php

declare(strict_types=1);

namespace AzGuardBench\Load;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Redis;
use RuntimeException;

/** Access to the explicitly configured phpredis-backed benchmark store. */
final class RedisCache
{
    /** @return array{Redis, string} */
    public static function clientAndPrefix(): array
    {
        $store = Cache::store('redis')->getStore();

        if (! $store instanceof RedisStore) {
            throw new RuntimeException('The benchmark requires a Redis cache store.');
        }
        $client = $store->connection()->client();

        if (! $client instanceof Redis) {
            throw new RuntimeException('The benchmark requires the phpredis client.');
        }

        return [$client, $client->getOption(Redis::OPT_PREFIX).$store->getPrefix()];
    }

    /** @return array{string, list<string>} */
    public static function scan(Redis $client, string $cursor, string $prefix): array
    {
        $result = $client->rawCommand('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '1000');

        if (! is_array($result) || ! isset($result[0], $result[1])
            || (! is_string($result[0]) && ! is_int($result[0])) || ! is_array($result[1])) {
            throw new RuntimeException('Unexpected Redis SCAN response.');
        }
        $keys = [];
        foreach ($result[1] as $key) {
            if (! is_string($key)) {
                throw new RuntimeException('Unexpected Redis key.');
            }
            $keys[] = $key;
        }

        return [(string) $result[0], $keys];
    }
}
