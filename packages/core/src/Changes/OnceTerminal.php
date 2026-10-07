<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\InvalidConfigurationException;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;

/**
 * @internal Runs one planned change through the `changing` pipes with guarded continuations.
 *
 * On the successful path every `$next` and the terminal run exactly once, and every pipe returns the very result its
 * `$next` returned, which is the writer's result. A skipped or repeated `$next`, a result made by a pipe, or a value
 * other than the change passed on fails with `InvalidConfigurationException` (check `changing`) and the whole mutation
 * rolls back. A pipe cancels by throwing.
 */
final class OnceTerminal
{
    private ?ChangeResult $result = null;

    private bool $terminated = false;

    /** @param Closure(mixed): ChangeResult $terminal final validation and the writer */
    private function __construct(private readonly Closure $terminal) {}

    /**
     * @param  list<Closure|object|class-string>  $pipes
     * @param  Closure(mixed): ChangeResult  $terminal
     */
    public static function run(Container $container, Change $planned, array $pipes, Closure $terminal): ChangeResult
    {
        $guard = new self($terminal);
        $returned = (new Pipeline($container))->send($planned)
            ->through(array_map(fn (object|string $pipe): Closure => $guard->guarded($container, $pipe), $pipes))
            ->then($guard->terminal(...));

        if ($guard->result === null || $returned !== $guard->result) {
            throw self::broken('The changing pipes did not return the writer result.');
        }

        return $guard->result;
    }

    private function terminal(mixed $final): ChangeResult
    {
        if ($this->terminated) {
            throw self::broken('A changing pipe reached the writer twice.');
        }
        $this->terminated = true;

        return $this->result = ($this->terminal)($final);
    }

    /** @param Closure|object|class-string $pipe */
    private function guarded(Container $container, object|string $pipe): Closure
    {
        return static function (mixed $change, Closure $next) use ($container, $pipe): ChangeResult {
            $called = false;
            $downstream = null;
            $guardedNext = static function (mixed $passed) use ($next, &$called, &$downstream): mixed {
                if ($called) {
                    throw self::broken('A changing pipe called $next twice.');
                }
                $called = true;

                return $downstream = $next($passed);
            };

            $returned = $pipe instanceof Closure ? $pipe($change, $guardedNext)
                : (is_string($pipe) ? $container->make($pipe) : $pipe)->handle($change, $guardedNext);

            if (! $called || ! $downstream instanceof ChangeResult || $returned !== $downstream) {
                throw self::broken('A changing pipe must call $next once and return its result.');
            }

            return $returned;
        };
    }

    private static function broken(string $message): InvalidConfigurationException
    {
        return InvalidConfigurationException::failing('changing', $message);
    }
}
