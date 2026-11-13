<?php

declare(strict_types=1);

namespace AzGuard\Tests\Support;

use Pest\Contracts\Plugins\HandlesArguments;

/**
 * ParaTest hands each repeated option to a worker as two argv tokens (`--exclude-group`, then `redis`).
 * Pest's popArgument() array_flips that list, so only one copy of the flag remains and PHPUnit keeps the first group.
 * Joining the pair into `--exclude-group=redis` before that flip leaves every group intact. `--group` has the same shape.
 */
final class PreserveRepeatedGroups implements HandlesArguments
{
    private const array OPTIONS = ['--exclude-group', '--group'];

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public function handleArguments(array $arguments): array
    {
        $normalized = [];
        $count = count($arguments);

        for ($index = 0; $index < $count; $index++) {
            $argument = $arguments[$index];
            $next = $arguments[$index + 1] ?? null;

            if (in_array($argument, self::OPTIONS, true) && is_string($next) && $next !== '' && ! str_starts_with($next, '-')) {
                $normalized[] = $argument.'='.$next;
                $index++;

                continue;
            }

            $normalized[] = $argument;
        }

        return $normalized;
    }
}
