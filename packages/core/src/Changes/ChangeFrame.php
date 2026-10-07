<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\InvalidConfigurationException;
use Closure;
use WeakMap;

/**
 * @internal Binds the changes of one pipeline attempt to their checked context. A retry gets a new frame.
 */
final class ChangeFrame
{
    /** @var WeakMap<Change, ChangeContext> */
    private WeakMap $contexts;

    /** @param Closure(Change): ChangeContext $context */
    public function __construct(private readonly Closure $context, public readonly string $correlationId)
    {
        $this->contexts = new WeakMap;
    }

    public function contextFor(Change $change): ChangeContext
    {
        if (! $change->belongsTo($this)) {
            throw InvalidConfigurationException::failing('changing', 'A change is read only inside its own pipeline attempt.');
        }

        return $this->contexts[$change] ??= ($this->context)($change);
    }
}
