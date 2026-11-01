<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;

it('round trips seeded identities and keeps framed tuples distinct', function (): void {
    $seen = [];
    for ($seed = 1; $seed <= 10; $seed++) {
        mt_srand($seed);
        for ($i = 0; $i < 10000; $i++) {
            $id = sprintf('%d:%d:%08x', $seed, $i, mt_rand());
            $ref = SubjectRef::of('user', $id);
            $encoded = IdentityCodec::encode($ref);

            if (IdentityCodec::encode(IdentityCodec::decode($encoded)) !== $encoded) {
                test()->fail("identity roundtrip seed=$seed case=$i");
            }
            $tuple = [$id, ['x', $seed, $i]];
            $key = IdentityCodec::compose($tuple);
            $left = IdentityCodec::compose([$id.':x', 'y']);
            $right = IdentityCodec::compose([$id, 'x:y']);

            if ($left === $right || isset($seen[$key]) || json_decode($key, true, 512, JSON_THROW_ON_ERROR) !== [1, ...$tuple]) {
                test()->fail("identity framing seed=$seed case=$i");
            }
            $seen[$key] = true;
        }
    }
    expect($seen)->toHaveCount(100000);
    expect(IdentityCodec::compose(['a:b', 'c']))->not->toBe(IdentityCodec::compose(['a', 'b:c']))
        ->and(IdentityCodec::compose([7]))->not->toBe(IdentityCodec::compose(['7']))
        ->and(IdentityCodec::compose(["a\0b", 'é']))->not->toBe(IdentityCodec::compose(['a', "\0bé"]))
        ->and(IdentityCodec::canonicalId(7))->toBe(IdentityCodec::canonicalId('7'))
        ->and(fn () => IdentityCodec::compose(["\xff"]))->toThrow(InvalidIdentityException::class);
});

it('rejects corpus identities and enforces label boundaries before lookup', function (): void {
    $corpus = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/Kernel/fuzz-corpus.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ([...$corpus['invalid_ids'], str_repeat('a', 65)] as $id) {
        expect(fn () => IdentityCodec::decode(['subject', 'user', $id]))->toThrow(InvalidIdentityException::class);
    }
    foreach ([...$corpus['invalid_aliases'], str_repeat('a', 129)] as $alias) {
        expect(fn () => IdentityCodec::decode(['subject', $alias, '1']))->toThrow(InvalidIdentityException::class);
    }
    foreach ([...$corpus['invalid_labels'], str_repeat('a', 129)] as $label) {
        expect(fn () => IdentityCodec::assertSourceLabel($label))->toThrow(InvalidIdentityException::class);
    }
    foreach (['', 'Admin', str_repeat('a', 65), 'a:b'] as $panel) {
        expect(fn () => IdentityCodec::decode(['permission', $panel, 'a.b']))->toThrow(InvalidIdentityException::class)
            ->and(fn () => IdentityCodec::decode(['pattern', $panel, 'a.*']))->toThrow(InvalidIdentityException::class);
    }
    IdentityCodec::assertTypeAlias(str_repeat('a', 128));
    IdentityCodec::assertSourceLabel(str_repeat('a', 128));
    expect(IdentityCodec::canonicalId(str_repeat('a', 64)))->toHaveLength(64);
});
