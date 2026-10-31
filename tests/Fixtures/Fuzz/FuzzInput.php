<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Fuzz;

use Closure;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * The deterministic input of the fuzz tests: a seeded generator of mixed strings (ASCII, the separators of the
 * grammars, unicode, NUL, very long runs) over the fixed corpus of tests/Fixtures/Fuzz/corpus.json. A failure names
 * its seed and the escaped input, so it reproduces exactly.
 */
final class FuzzInput
{
    public const int DEFAULT_ITERATIONS = 500;

    public const int DEEP_ITERATIONS = 20000;

    /** The slowest input accepted by a parser, in milliseconds (a catastrophic pattern would exceed it). */
    public const float BUDGET_MS = 50.0;

    private const array ALPHABET = ['a', 'b', 'z', '0', '9', '-', '_', '.', ':', '*', '**', '@', '/', ' ', "\t", "\n", "\0", 'A', 'É', '漢', "\u{202E}", '%', '\\', '"', "'", '{', '}', '[', ']'];

    private Randomizer $random;

    public function __construct(public readonly int $seed)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /** `AZGUARD_FUZZ_ITERATIONS` if set, else `$default`. */
    public static function iterations(int $default = self::DEFAULT_ITERATIONS): int
    {
        $set = getenv('AZGUARD_FUZZ_ITERATIONS');

        return $set !== false && ctype_digit($set) && (int) $set > 0 ? (int) $set : $default;
    }

    /** @return list<string> the fixed inputs: R03/R06/R08 shapes, grammar edges, hostile bytes */
    public static function corpus(): array
    {
        $file = json_decode((string) file_get_contents(__DIR__.'/corpus.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn (string $entry): string => str_replace('{LONG}', str_repeat('a.', 400), stripcslashes($entry)), $file['inputs']);
    }

    /** A mixed string of up to `$max` pieces. */
    public function string(int $max = 24): string
    {
        $pieces = $this->random->getInt(0, $max);
        $out = '';

        for ($i = 0; $i < $pieces; $i++) {
            $out .= self::ALPHABET[$this->random->getInt(0, count(self::ALPHABET) - 1)];
        }

        return $out;
    }

    /** A string close to the permission grammar: mostly valid segments, a wildcard tail and seeded damage. */
    public function keyish(): string
    {
        $segments = [];

        for ($i = $this->random->getInt(1, 5); $i > 0; $i--) {
            $segment = '';

            for ($j = $this->random->getInt(1, 7); $j > 0; $j--) {
                $segment .= ['a', 'b', 'c', 'x', 'y', '0', '1', '9', '-', '_'][$this->random->getInt(0, 9)];
            }
            $segments[] = ltrim($segment, '-_') === '' ? 'a' : ltrim($segment, '-_');
        }

        if ($this->random->getInt(0, 3) === 0) {
            $segments[] = $this->random->getInt(0, 1) === 0 ? '*' : '**';
        }
        $key = implode('.', $segments);

        return $this->random->getInt(0, 2) === 0 ? $this->damage($key) : $key;
    }

    /** Damages a valid text: truncate, insert, replace or repeat. */
    public function damage(string $text): string
    {
        return match ($this->random->getInt(0, 4)) {
            0 => substr($text, 0, $this->random->getInt(0, max(0, strlen($text)))),
            1 => substr_replace($text, $this->string(3), $this->random->getInt(0, strlen($text)), 0),
            2 => substr_replace($text, $this->string(2), $this->random->getInt(0, max(0, strlen($text) - 1)), 1),
            3 => $text.$text,
            default => strrev($text),
        };
    }

    public function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    /**
     * Runs `$check` over the corpus and `$iterations` generated inputs; each call is measured against the budget.
     *
     * @param  Closure(string, string): void  $check  receives the input and its failure label
     */
    public static function run(int $seed, int $iterations, Closure $check, ?Closure $generate = null): void
    {
        $input = new self($seed);
        $generate ??= static fn (self $input): string => $input->string();
        $inputs = self::corpus();

        for ($i = 0; $i < $iterations; $i++) {
            $inputs[] = $generate($input);
        }

        foreach ($inputs as $index => $value) {
            $label = sprintf('seed=%d #%d input=%s', $seed, $index, addcslashes(substr($value, 0, 120), "\0..\37\177..\377"));
            $start = hrtime(true);
            $check($value, $label);
            $elapsed = (hrtime(true) - $start) / 1e6;

            if ($elapsed > self::BUDGET_MS) {
                throw new RuntimeException(sprintf('Took %.1f ms (budget %.0f ms): %s', $elapsed, self::BUDGET_MS, $label));
            }
        }
    }
}
