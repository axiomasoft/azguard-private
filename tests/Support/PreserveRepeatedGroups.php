<?php

declare(strict_types=1);

namespace AzGuard\Tests\Support;

use Pest\Contracts\Plugins\HandlesArguments;

/**
 * ParaTest hands each repeated option to a worker as two argv tokens (`--exclude-group`, then `redis`).
 * Pest's popArgument() array_flips that list, so only one copy of the flag remains and PHPUnit keeps the first group.
 * Joining the pair into `--exclude-group=redis` before that flip leaves every group intact. `--group` has the same shape.
 * bin/pest unsets `--compact` and leaves a hole. A missing key reads as null, and Coverage's array_filter then
 * rejects that null and aborts the lane. array_values closes the hole before the walk, and only strings are returned.
 */
final class PreserveRepeatedGroups implements HandlesArguments
{
    private const array OPTIONS = ['--exclude-group', '--group'];

    /**
     * @param  array<mixed>  $arguments
     * @return list<string>
     */
    public function handleArguments(array $arguments): array
    {
        // A hole is not an element: count() shrinks, but the key stays missing until the list is reindexed.
        $arguments = array_values($arguments);
        $normalized = [];
        $count = count($arguments);

        for ($index = 0; $index < $count; $index++) {
            $argument = $arguments[$index];

            if (! is_string($argument)) {
                continue;
            }

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
