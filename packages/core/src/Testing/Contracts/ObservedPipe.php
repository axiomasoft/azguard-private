<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\Kernel\Support\Narrow;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use LogicException;

/**
 * @internal Runs the pipe of the author and counts the statements that change data which the pipe itself issued: the
 * ones that ran before it passed the change on or after the writer returned. What the writer issued inside `$next` is
 * the writer's and is not counted.
 */
final class ObservedPipe implements ChangePipe
{
    /** @var list<string> */
    private array $log = [];

    /** @var list<string> */
    public array $outside = [];

    /** @param ChangePipe|class-string<ChangePipe>|Closure(Change, Closure(Change): ChangeResult): ChangeResult $pipe */
    public function __construct(private readonly ChangePipe|Closure|string $pipe)
    {
        app('events')->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (WriteLog::isWrite($query->sql)) {
                $this->log[] = $query->sql;
            }
        });
    }

    public function handle(Change $change, Closure $next): ChangeResult
    {
        $start = count($this->log);
        $window = [0, 0];
        $guarded = function (Change $passed) use ($next, &$window): ChangeResult {
            $window[0] = count($this->log);

            try {
                return Narrow::instance($next($passed), ChangeResult::class, 'the downstream change result');
            } finally {
                $window[1] = count($this->log);
            }
        };

        try {
            $pipe = is_string($this->pipe) ? app(Container::class)->make($this->pipe) : $this->pipe;

            if (! $pipe instanceof Closure && ! $pipe instanceof ChangePipe) {
                throw new LogicException('The observed pipe must be a closure or implement '.ChangePipe::class.'.');
            }

            return Narrow::instance($pipe instanceof Closure ? $pipe($change, $guarded) : $pipe->handle($change, $guarded), ChangeResult::class, 'the observed pipe result');
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
