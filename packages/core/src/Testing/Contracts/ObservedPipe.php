<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Events\QueryExecuted;

/**
 * @internal Runs the pipe of the author and counts the statements that change data which the pipe itself issued: the
 * ones that ran before it passed the change on or after the writer returned. What the writer issued inside `$next` is
 * the writer's and is not counted.
 */
final class ObservedPipe
{
    /** @var list<string> */
    private array $log = [];

    /** @var list<string> */
    public array $outside = [];

    /** @param object|string $pipe a closure, an object with `handle()` or the name of a class that has one */
    public function __construct(private readonly object|string $pipe)
    {
        app('events')->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql) === 1) {
                $this->log[] = $query->sql;
            }
        });
    }

    public function handle(Change $change, Closure $next): ChangeResult
    {
        $start = count($this->log);
        $window = [0, 0];
        $guarded = function (mixed $passed) use ($next, &$window): mixed {
            $window[0] = count($this->log);

            try {
                return $next($passed);
            } finally {
                $window[1] = count($this->log);
            }
        };

        try {
            $pipe = is_string($this->pipe) ? app(Container::class)->make($this->pipe) : $this->pipe;

            return $pipe instanceof Closure ? $pipe($change, $guarded) : $pipe->handle($change, $guarded);
        } finally {
            foreach (array_slice($this->log, $start) as $offset => $sql) {
                $index = $start + $offset;

                if ($index < $window[0] || $index >= $window[1]) {
                    $this->outside[] = $sql;
                }
            }
        }
    }
}
