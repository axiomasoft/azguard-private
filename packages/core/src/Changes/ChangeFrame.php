<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\InvalidConfigurationException;
use Closure;
use DateTimeImmutable;
use WeakMap;

/**
 * @internal Binds the changes of one pipeline attempt to their checked context. A retry gets a new frame.
 */
final class ChangeFrame
{
    /** @var WeakMap<Change, ChangeContext> */
    private WeakMap $contexts;

    /** @var list<string> grants removed by the changes of this attempt that already ran */
    private array $removed = [];

    /** @param Closure(Change): ChangeContext $context */
    public function __construct(private readonly Closure $context, public readonly string $correlationId, public readonly DateTimeImmutable $now)
    {
        $this->contexts = new WeakMap;
    }

    /** Remembers the grants a finished change removed, for the delivery of a later change of the same operation. */
    public function record(ChangeResult $result): void
    {
        array_push($this->removed, ...$result->removedGrantIds());
    }

    /** @return list<string> */
    public function removed(): array
    {
        return $this->removed;
    }

    public function contextFor(Change $change): ChangeContext
    {
        if (! $change->belongsTo($this)) {
            throw InvalidConfigurationException::failing('changing', 'A change is read only inside its own pipeline attempt.');
        }

        return $this->contexts[$change] ??= ($this->context)($change);
    }
}
