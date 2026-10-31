<?php

declare(strict_types=1);

namespace AzGuard\Configuration\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/** @internal Validated signing material shared by configuration and the cache envelope. */
final class CacheSigningConfig
{
    /** @return array{namespace: string, active: string, keys: array<string, array{key: string, until: ?DateTimeImmutable}>}|null */
    public static function fromConfig(Repository $config): ?array
    {
        $namespace = $config->get('azguard.cache.namespace');
        $active = $config->get('azguard.cache.key_id', 'active');
        $secret = $config->get('azguard.cache.signing_key');

        if ($secret === null || $secret === '') {
            $secret = $config->get('app.key');
        }

        if (! is_string($namespace) || $namespace === '' || strlen($namespace) > 255 || preg_match('//u', $namespace) !== 1 || ! is_string($active)
            || preg_match('/\A[a-zA-Z0-9_.-]{1,64}\z/', $active) !== 1 || ! is_string($secret)) {
            return null;
        }
        $key = self::key($secret);

        if ($key === null) {
            return null;
        }
        $keys = [$active => ['key' => $key, 'until' => null]];
        $previous = $config->get('azguard.cache.previous_keys', []);

        if (! is_array($previous)) {
            return null;
        }
        foreach ($previous as $id => $entry) {
            if (! is_string($id) || $id === $active || preg_match('/\A[a-zA-Z0-9_.-]{1,64}\z/', $id) !== 1
                || ! is_array($entry) || ! is_string($entry['key'] ?? null) || ! is_string($entry['valid_until'] ?? null)) {
                return null;
            }
            $oldKey = self::key($entry['key']);
            $until = self::parseTime($entry['valid_until']);

            if ($oldKey === null || $until === null) {
                return null;
            }
            $keys[$id] = ['key' => $oldKey, 'until' => $until];
        }

        return ['namespace' => $namespace, 'active' => $active, 'keys' => $keys];
    }

    private static function key(string $secret): ?string
    {
        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7), true);
        }

        return is_string($secret) && strlen($secret) >= 32 ? $secret : null;
    }

    public static function parseTime(string $value): ?DateTimeImmutable
    {
        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));

            return $date !== false && $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z') === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }
}
