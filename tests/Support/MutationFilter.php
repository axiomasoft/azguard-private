<?php

declare(strict_types=1);

namespace AzGuard\Tests\Support;

use RuntimeException;

/** Development-only workaround for pestphp/pest#1771; never shipped with either package. */
final class MutationFilter
{
    private static bool $reported = false;

    /** @param list<string> $filters */
    public static function argument(array $filters): string
    {
        $pattern = implode('|', $filters);

        if (self::usable($pattern)) {
            return '--filter={'.$pattern.'}i';
        }

        $classes = [];

        foreach ($filters as $filter) {
            if (preg_match('/^([A-Za-z0-9_]+)::/', $filter, $matches) !== 1) {
                throw new RuntimeException('Unrecognized mutation test filter; refusing to omit covering tests.');
            }

            $classes[$matches[1]] = $matches[1].'::';
        }

        // Selecting entire covering classes retains every covering test and may run additional tests.
        $pattern = implode('|', $classes);

        if (! self::usable($pattern)) {
            throw new RuntimeException('Mutation class filter exceeds the process or regex budget.');
        }

        if (! self::$reported) {
            fwrite(STDERR, "[mutation-gate] Oversized or invalid regex: selecting entire covering test classes.\n");
            self::$reported = true;
        }

        return '--filter={'.$pattern.'}i';
    }

    public static function assertTestsRan(string $output): void
    {
        if (str_contains($output, 'No tests found') || str_contains($output, 'No tests executed')) {
            throw new RuntimeException('Mutation worker selected no tests; refusing to count a runner error as a killed mutant.');
        }
    }

    private static function usable(string $pattern): bool
    {
        // Both kernel argv and PCRE compilation limits matter. Compile before launching a child.
        return $pattern !== '' && strlen($pattern) < 32768 && @preg_match('{'.$pattern.'}i', '') !== false;
    }
}
