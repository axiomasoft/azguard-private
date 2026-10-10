<?php

declare(strict_types=1);

use AzGuard\Tests\Support\MutationFilter;

it('keeps small valid mutation selections exact', function (): void {
    $argument = MutationFilter::argument(['ProbeTest::(.*)allows', 'ProbeTest::(.*)denies']);
    $regex = substr($argument, strlen('--filter='));

    expect(preg_match($regex, 'P\\Tests\\ProbeTest::__pest_evaluable_allows'))->toBe(1)
        ->and(preg_match($regex, 'P\\Tests\\ProbeTest::__pest_evaluable_denies'))->toBe(1)
        ->and(preg_match($regex, 'P\\Tests\\ProbeTest::__pest_evaluable_other'))->toBe(0);
});

it('retains every covering class when an oversized or invalid regex must widen', function (array $filters): void {
    $argument = MutationFilter::argument($filters);
    $regex = substr($argument, strlen('--filter='));

    expect(strlen($argument))->toBeLessThan(32768)
        ->and(preg_match($regex, 'P\\Tests\\FirstTest::__pest_evaluable_covers'))->toBe(1)
        ->and(preg_match($regex, 'P\\Tests\\LastTest::__pest_evaluable_covers'))->toBe(1)
        ->and(preg_match($regex, 'P\\Tests\\OtherTest::__pest_evaluable_covers'))->toBe(0);
})->with([
    'kernel limit' => [[...array_map(static fn (int $index): string => 'FirstTest::(.*)'.str_repeat('test', 30).$index, range(1, 1500)), 'LastTest::(.*)covers']],
    'regex syntax' => [['FirstTest::(.*)[', 'LastTest::(.*)covers']],
    'PCRE compilation limit' => [[...array_map(static fn (int $index): string => 'FirstTest::(.*)'.str_repeat('(a)', 8).$index, range(1, 650)), 'LastTest::(.*)covers']],
]);

it('refuses malformed oversized filters rather than dropping tests', function (): void {
    expect(fn () => MutationFilter::argument([str_repeat('unknown', 20000)]))->toThrow(RuntimeException::class);
});

it('refuses an empty mutation worker result', function (string $output): void {
    expect(fn () => MutationFilter::assertTestsRan($output))->toThrow(RuntimeException::class);
})->with(['No tests found.', 'No tests executed!']);

it('accepts genuine mutant failure output', function (): void {
    MutationFilter::assertTestsRan('Tests: 1 failed (1 assertions)');
    expect(true)->toBeTrue();
});
