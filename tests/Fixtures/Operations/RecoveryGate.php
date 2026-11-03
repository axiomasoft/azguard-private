<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Operations;

use RuntimeException;

/** Reference host fence only; AzGuard does not operate production ingress or workers. */
final class RecoveryGate
{
    private const STEPS = ['restore', 'reset', 'warmup', 'doctor', 'smoke'];

    private array $passed = [];

    public bool $maintenance = true;

    public function record(string $step, bool $passed): void
    {
        $index = array_search($step, self::STEPS, true);

        if ($index === false) {
            throw new RuntimeException('Unknown recovery step.');
        }

        // Repeating an earlier step invalidates evidence from the previous recovery attempt.
        foreach (array_slice(self::STEPS, $index) as $later) {
            unset($this->passed[$later]);
        }

        $this->passed[$step] = $passed;
        $this->maintenance = true;
    }

    public function open(): void
    {
        foreach (self::STEPS as $step) {
            if (($this->passed[$step] ?? false) !== true) {
                throw new RuntimeException('Recovery evidence incomplete; maintenance remains closed.');
            }
        }

        $this->maintenance = false;
    }
}
