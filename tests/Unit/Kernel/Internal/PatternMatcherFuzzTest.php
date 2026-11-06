<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Internal\PatternMatcher;

it('matches seeded patterns with an independent segment interpreter', function (): void {
    $reference = static function (string $pattern, string $key): bool {
        $p = explode('.', $pattern);
        $k = explode('.', $key);
        foreach ($p as $i => $segment) {
            if ($segment === '**') {
                return count($k) > $i;
            }

            if (! isset($k[$i]) || ($segment !== '*' && $segment !== $k[$i])) {
                return false;
            }
        }

        return count($p) === count($k);
    };
    $segments = ['a', 'b', 'orders', 'view', 'x-1', 'a_b'];
    for ($seed = 1; $seed <= 10; $seed++) {
        mt_srand($seed);
        for ($i = 0; $i < 10000; $i++) {
            $prefix = [];
            for ($j = 0, $n = mt_rand(1, 5); $j < $n; $j++) {
                $prefix[] = $segments[mt_rand(0, count($segments) - 1)];
            }
            $pattern = implode('.', [...$prefix, ['*', '**', 'view'][mt_rand(0, 2)]]);
            $keyParts = mt_rand(0, 1) ? $prefix : [$segments[mt_rand(0, count($segments) - 1)]];
            for ($j = 0, $n = mt_rand(0, 5); $j < $n; $j++) {
                $keyParts[] = $segments[mt_rand(0, count($segments) - 1)];
            }

            if (count($keyParts) < 2) {
                $keyParts[] = 'view';
            }
            $key = implode('.', $keyParts);

            if (PatternMatcher::covers($pattern, $key) !== $reference($pattern, $key)) {
                test()->fail("matcher seed=$seed case=$i pattern=$pattern key=$key");
            }
        }
    }
    expect(PatternMatcher::covers('a.*', 'a.b.c'))->toBeFalse()
        ->and(PatternMatcher::covers('a.**', 'a.b.c'))->toBeTrue()
        ->and(PatternMatcher::covers('a.b.**', 'a.b'))->toBeFalse();
});

it('rejects malformed corpus patterns and keys rather than broadening a grant', function (): void {
    $corpus = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/Kernel/fuzz-corpus.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ([...$corpus['invalid_patterns'], 'a.'.str_repeat('b', 254), str_repeat('a.', 128).'b'] as $bad) {
        expect(fn () => PatternMatcher::covers($bad, 'a.b'))->toThrow(InvalidPermissionKeyException::class)
            ->and(fn () => PatternMatcher::covers('a.**', $bad))->toThrow(InvalidPermissionKeyException::class);
    }
});
