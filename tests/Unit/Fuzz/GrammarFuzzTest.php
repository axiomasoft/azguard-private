<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Tests\Fixtures\Fuzz\FuzzInput;

/*
 * Fuzz of the permission grammar and the identity codecs (knowledge/qualification#fuzz): seeded, deterministic, the
 * seed and the escaped input in every failure. Blockers: an uncaught non-domain exception, a PHP warning, an input over
 * the time budget, a predicate that disagrees with its assertion, a parse that does not round-trip.
 */

/** @param callable(string): void $call */
function expectDomainFailure(callable $call, string $exception, string $label): void
{
    try {
        $call($label);
    } catch (Throwable $error) {
        expect($error)->toBeInstanceOf($exception, $label.' threw '.$error::class);

        return;
    }

    throw new RuntimeException('expected '.$exception.': '.$label);
}

function grammarProperties(int $seed, int $iterations): void
{
    $keyish = static fn (FuzzInput $input): string => $input->int(0, 1) === 0 ? $input->keyish() : $input->string();

    FuzzInput::run($seed, $iterations, static function (string $value, string $label): void {
        foreach ([
            ['isLocalKey', 'assertLocalKey', InvalidPermissionKeyException::class],
            ['isPattern', 'assertPattern', InvalidPermissionKeyException::class],
            ['isPanelId', 'assertPanelId', InvalidPanelIdException::class],
            ['isRoleKey', 'assertRoleKey', InvalidRoleKeyException::class],
        ] as [$is, $assert, $exception]) {
            if (PermissionGrammar::$is($value)) {
                PermissionGrammar::$assert($value);
            } else {
                expectDomainFailure(static fn () => PermissionGrammar::$assert($value), $exception, "$assert $label");
            }
        }

        // A local key is a pattern; a pattern with a wildcard is not a local key.
        if (PermissionGrammar::isLocalKey($value)) {
            expect(PermissionGrammar::isPattern($value))->toBeTrue("local key is a pattern: $label");
        }

        if (PermissionGrammar::isFullKey($value)) {
            [$panel, $local] = PermissionGrammar::splitFull($value);
            expect($panel.':'.$local)->toBe($value, "splitFull round trip: $label")
                ->and(PermissionKey::parse($value)->full())->toBe($value, "PermissionKey round trip: $label");
        } else {
            expectDomainFailure(static fn () => PermissionKey::parse($value), InvalidIdentityException::class, "PermissionKey::parse $label");
            expectDomainFailure(static fn () => PermissionGrammar::splitFull($value), InvalidIdentityException::class, "splitFull $label");
        }

        if (PermissionGrammar::isRoleKey($value) === false) {
            expectDomainFailure(static fn () => RoleKey::of('panel', $value), InvalidIdentityException::class, "RoleKey::of $label");
        }
    }, $keyish);
}

function patternProperties(int $seed, int $iterations): void
{
    FuzzInput::run($seed, $iterations, static function (string $value, string $label): void {
        if (! PermissionGrammar::isPattern($value)) {
            expectDomainFailure(static fn () => PermissionPattern::of('panel', $value), InvalidIdentityException::class, "PermissionPattern::of $label");

            return;
        }
        $pattern = PermissionPattern::of('panel', $value);
        expect($pattern->full())->toBe('panel:'.$value, $label);

        // An exact pattern covers exactly its own key; any pattern never covers a key of another panel.
        if (PermissionGrammar::isLocalKey($value)) {
            expect($pattern->isExact())->toBeTrue($label)
                ->and($pattern->covers(PermissionKey::of('panel', $value)))->toBeTrue($label)
                ->and($pattern->covers(PermissionKey::of('other', $value)))->toBeFalse($label);
        } else {
            expect($pattern->isExact())->toBeFalse($label);
        }
    }, static fn (FuzzInput $input): string => $input->keyish());
}

function identifierProperties(int $seed, int $iterations): void
{
    FuzzInput::run($seed, $iterations, static function (string $value, string $label): void {
        try {
            $canonical = IdentityCodec::canonicalId($value);
        } catch (InvalidIdentityException) {
            // A refused id stays refused when it is the decimal text of an int that was refused as such.
            return;
        }

        expect($canonical)->toBe($value, "canonicalId keeps accepted bytes: $label")
            ->and(IdentityCodec::canonicalId($canonical))->toBe($canonical, "idempotent: $label");

        try {
            IdentityCodec::assertTypeAlias($value);
        } catch (InvalidIdentityException) {
            // The alias grammar is narrower than the id grammar.
        }
    });

    $input = new FuzzInput($seed);

    for ($i = 0; $i < $iterations; $i++) {
        $number = $input->int(PHP_INT_MIN, PHP_INT_MAX);

        try {
            expect(IdentityCodec::canonicalId($number))->toBe((string) $number, "seed=$seed int=$number");
        } catch (InvalidIdentityException) {
            // A negative id may be outside the id grammar; it must fail with the domain exception only.
        }
    }
}

it('keeps the grammar predicates, assertions and parsers in agreement', function (int $seed): void {
    grammarProperties($seed, FuzzInput::iterations());
})->with([1, 2, 3]);

it('keeps PermissionPattern coverage consistent with the grammar', function (int $seed): void {
    patternProperties($seed, FuzzInput::iterations());
})->with([1, 2, 3]);

it('canonicalises identifiers byte for byte or refuses them', function (int $seed): void {
    identifierProperties($seed, FuzzInput::iterations());
})->with([1, 2, 3]);

it('keeps the grammar properties over deep iterations', function (int $seed): void {
    grammarProperties($seed, FuzzInput::iterations(FuzzInput::DEEP_ITERATIONS));
    patternProperties($seed, FuzzInput::iterations(FuzzInput::DEEP_ITERATIONS));
    identifierProperties($seed, FuzzInput::iterations(FuzzInput::DEEP_ITERATIONS));
})->with([11])->group('extended');

it('reaches both valid and invalid inputs of the grammar', function (): void {
    $input = new FuzzInput(7);
    $valid = $invalid = 0;

    for ($i = 0; $i < 2000; $i++) {
        PermissionGrammar::isPattern($input->keyish()) ? $valid++ : $invalid++;
    }

    expect($valid)->toBeGreaterThan(20)->and($invalid)->toBeGreaterThan(20);
});
