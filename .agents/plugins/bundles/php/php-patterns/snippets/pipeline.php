<?php

declare(strict_types=1);

namespace App\Domain\Pipeline;

/**
 * Pipeline: sequential transformation of one value by a chain of steps.
 * Each step is an independent, testable, rearranged object/closure.
 * Suitable when processing is divided into stages (normalization → enrichment → calculation).
 *
 * B Laravel has a built-in Illuminate\Pipeline\Pipeline and facade Pipeline —
 * use it; this class shows the contract when the framework one is not available
 * or the dependency is undesirable.
 */

interface PipeStage
{
    public function handle(mixed $payload, callable $next): mixed;
}

final class Pipeline
{
    /** @var list<PipeStage> */
    private array $stages = [];

    /** @param list<PipeStage> $stages */
    public function through(array $stages): self
    {
        $this->stages = $stages;

        return $this;
    }

    public function process(mixed $payload): mixed
    {
        // handle() is called positionally: the step implementation parameter names are not
        // fixed by interface PipeStage — named arg through the interface breaks polymorphism.
        $chain = array_reduce(
            array: array_reverse($this->stages),
            callback: fn (callable $next, PipeStage $stage): callable
                => fn (mixed $value): mixed => $stage->handle($value, $next),
            initial: fn (mixed $value): mixed => $value,
        );

        return $chain($payload);
    }
}

/**
 * Example step: clean, no side effects on other people's data, can be tested in isolation.
 * Call to Action: (new Pipeline())->through([new TrimName(), new Capitalize()])->process($data);
 */
final class TrimName implements PipeStage
{
    public function handle(mixed $payload, callable $next): mixed
    {
        $payload->name = trim(string: $payload->name);

        return $next($payload);
    }
}
