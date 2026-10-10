<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Grammar;

use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;

/**
 * The single grammar of permission keys, grant patterns, panel ids and role keys.
 *
 * A local key is two or more dot-separated lowercase segments, at most 255 bytes, without wildcards.
 * A full key is `panel:local`. A pattern is a local key whose last segment may be `*` (exactly one more
 * segment) or `**` (one or more segments); bare wildcards are never valid, "everything" is a super-admin role.
 */
final class PermissionGrammar
{
    public const int MAX_LOCAL_LENGTH = 255;

    public const string WILDCARD_ONE = '*';

    public const string WILDCARD_DEEP = '**';

    private const string SEGMENT_PATTERN = '/\A[a-z0-9][a-z0-9_-]*\z/';

    private const string ID_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,63}\z/';

    private function __construct() {}

    public static function isSegment(string $value): bool
    {
        return preg_match(self::SEGMENT_PATTERN, $value) === 1;
    }

    public static function isLocalKey(string $value): bool
    {
        return self::localKeyViolation($value, false) === null;
    }

    public static function isPattern(string $value): bool
    {
        return self::localKeyViolation($value, true) === null;
    }

    public static function isFullKey(string $value): bool
    {
        $parts = explode(':', $value);

        return count($parts) === 2 && self::isPanelId($parts[0]) && self::isLocalKey($parts[1]);
    }

    public static function isPanelId(string $value): bool
    {
        return preg_match(self::ID_PATTERN, $value) === 1;
    }

    public static function isRoleKey(string $value): bool
    {
        return preg_match(self::ID_PATTERN, $value) === 1;
    }

    /**
     * @throws InvalidPermissionKeyException
     */
    public static function assertLocalKey(string $value): void
    {
        $violation = self::localKeyViolation($value, false);

        if ($violation !== null) {
            throw new InvalidPermissionKeyException(self::message('permission key', $value, $violation));
        }
    }

    /**
     * @throws InvalidPermissionKeyException
     */
    public static function assertPattern(string $value): void
    {
        $violation = self::localKeyViolation($value, true);

        if ($violation !== null) {
            throw new InvalidPermissionKeyException(self::message('permission pattern', $value, $violation));
        }
    }

    /**
     * @throws InvalidPanelIdException
     */
    public static function assertPanelId(string $value): void
    {
        if (! self::isPanelId($value)) {
            throw new InvalidPanelIdException(self::message('panel id', $value, 'must match '.self::ID_PATTERN));
        }
    }

    /**
     * @throws InvalidRoleKeyException
     */
    public static function assertRoleKey(string $value): void
    {
        if (! self::isRoleKey($value)) {
            throw new InvalidRoleKeyException(self::message('role key', $value, 'must match '.self::ID_PATTERN));
        }
    }

    /**
     * Splits a full key `panel:local` after validating both parts.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidPermissionKeyException
     * @throws InvalidPanelIdException
     */
    public static function splitFull(string $full): array
    {
        $parts = explode(':', $full);

        if (count($parts) !== 2) {
            throw new InvalidPermissionKeyException(
                self::message('full permission key', $full, 'must contain exactly one ":" between panel id and local key'),
            );
        }

        self::assertPanelId($parts[0]);
        self::assertLocalKey($parts[1]);

        return [$parts[0], $parts[1]];
    }

    private static function localKeyViolation(string $value, bool $pattern): ?string
    {
        if (strlen($value) > self::MAX_LOCAL_LENGTH) {
            return 'must not exceed '.self::MAX_LOCAL_LENGTH.' bytes';
        }

        $segments = explode('.', $value);

        if (count($segments) < 2) {
            return 'must have at least two dot-separated segments';
        }

        $last = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (self::isSegment($segment)) {
                continue;
            }

            if ($segment === self::WILDCARD_ONE || $segment === self::WILDCARD_DEEP) {
                if (! $pattern) {
                    return 'must not contain wildcards';
                }

                if ($index !== $last) {
                    return 'may use a wildcard only as the last segment';
                }

                continue;
            }

            return 'segment '.self::encode($segment).' must match '.self::SEGMENT_PATTERN;
        }

        return null;
    }

    private static function message(string $subject, string $value, string $rule): string
    {
        return 'Invalid '.$subject.' '.self::encode($value).': '.$rule.'.';
    }

    private static function encode(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
