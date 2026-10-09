<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Grammar;

use AzGuard\Exceptions\InvalidPermissionKeyException;

/**
 * Segment-wise coverage of a local permission key by a grant pattern; no regex is built from input.
 */
final class PatternMatcher
{
    private function __construct() {}

    /**
     * `p.*` covers exactly one segment after `p.`, `p.**` covers one or more, an exact pattern covers itself.
     *
     * @throws InvalidPermissionKeyException when the pattern or the key violates the grammar
     */
    public static function covers(string $pattern, string $local): bool
    {
        PermissionGrammar::assertPattern($pattern);
        PermissionGrammar::assertLocalKey($local);

        return self::coversValidated($pattern, $local);
    }

    /**
     * `covers()` for values that already passed the grammar: pattern and key value objects and compiled roles. The
     * decision path calls this for every pattern of every role, so it skips the second validation.
     *
     * @internal
     */
    public static function coversValidated(string $pattern, string $local): bool
    {
        if ($pattern === $local || ! str_ends_with($pattern, '*')) {
            return $pattern === $local;
        }

        $patternSegments = explode('.', $pattern);
        $wildcard = array_pop($patternSegments);

        $localSegments = explode('.', $local);
        $prefixLength = count($patternSegments);

        if (array_slice($localSegments, 0, $prefixLength) !== $patternSegments) {
            return false;
        }

        $rest = count($localSegments) - $prefixLength;

        return $wildcard === PermissionGrammar::WILDCARD_ONE ? $rest === 1 : $rest >= 1;
    }
}
