<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use ArrayIterator;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Kernel\Identity\IdentityCodec;
use Countable;
use IteratorAggregate;
use OutOfRangeException;

/**
 * Ordered decisions of one batch check and the distinct states they were made against.
 *
 * @implements IteratorAggregate<int, Decision>
 */
final readonly class DecisionSet implements Countable, IteratorAggregate
{
    /**
     * @param  list<Decision>  $decisions
     * @param  array<string, CodeStateToken|StateToken>  $states
     */
    private function __construct(
        private array $decisions,
        private array $states,
    ) {}

    /**
     * @throws ConsistencyException when two decisions carry different tokens for the same code build or storage panel
     */
    public static function of(Decision ...$decisions): self
    {
        $states = [];

        foreach ($decisions as $decision) {
            $state = $decision->state;
            $key = $state instanceof CodeStateToken
                ? IdentityCodec::compose(['code', $state->panel, $state->buildId])
                : IdentityCodec::compose(['storage', $state->storageId, $state->panel]);
            $known = $states[$key] ?? $state;

            if (! ($known instanceof CodeStateToken && $state instanceof CodeStateToken && $known->equals($state))
                && ! ($known instanceof StateToken && $state instanceof StateToken && $known->equals($state))) {
                throw new ConsistencyException('Decisions of one set carry different state tokens for '.$key.'.');
            }

            $states[$key] = $state;
        }

        return new self(array_values($decisions), $states);
    }

    /**
     * @throws OutOfRangeException
     */
    public function get(int $i): Decision
    {
        return $this->decisions[$i] ?? throw new OutOfRangeException('No decision at index '.$i.'.');
    }

    /**
     * @return array<string, CodeStateToken|StateToken> keyed by an identity composite of code/panel/build or storage/panel
     */
    public function states(): array
    {
        return $this->states;
    }

    public function count(): int
    {
        return count($this->decisions);
    }

    /**
     * @return ArrayIterator<int, Decision>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->decisions);
    }
}
