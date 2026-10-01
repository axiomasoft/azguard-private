<?php

declare(strict_types=1);

namespace AzGuard\Tests\Unit\Kernel\Identity;

/**
 * Deterministic random identity parts for property tests; ids favour JSON and key separators.
 */
final class IdentityGenerator
{
    public const int SEED = 20261001;

    private const string ALIAS_FIRST = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const string ALIAS_REST = 'abcdefghijklmnopqrstuvwxyz0123456789_.-';

    private const string ID_CHARS = ':"\\[],{}a7 0';

    private const string SEGMENT_REST = 'abcdefghijklmnopqrstuvwxyz0123456789_-';

    public static function seed(): void
    {
        mt_srand(self::SEED);
    }

    public static function alias(int $maxLength = 12): string
    {
        return self::pick(self::ALIAS_FIRST).self::string(self::ALIAS_REST, mt_rand(0, $maxLength - 1));
    }

    /**
     * Printable ASCII id (the space in ID_CHARS is swapped for a tilde) or an int.
     */
    public static function id(int $maxLength = 8): int|string
    {
        if (mt_rand(0, 3) === 0) {
            return mt_rand(-1000, 1_000_000);
        }

        return str_replace(' ', '~', self::string(self::ID_CHARS, mt_rand(1, $maxLength)));
    }

    public static function panel(): string
    {
        return self::pick(self::ALIAS_FIRST).self::string('abcdefghijklmnopqrstuvwxyz0123456789-', mt_rand(0, 10));
    }

    public static function localKey(): string
    {
        $segments = [];

        for ($i = mt_rand(2, 4); $i > 0; $i--) {
            $segments[] = self::pick(self::ALIAS_FIRST).self::string(self::SEGMENT_REST, mt_rand(0, 8));
        }

        return implode('.', $segments);
    }

    public static function string(string $chars, int $length): string
    {
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= self::pick($chars);
        }

        return $result;
    }

    private static function pick(string $chars): string
    {
        return $chars[mt_rand(0, strlen($chars) - 1)];
    }
}
