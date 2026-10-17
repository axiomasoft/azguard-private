<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Tests\Fixtures\Fuzz\FuzzInput;

/*
 * Fuzz of the state token readers (knowledge/qualification#fuzz): arbitrary text is a typed `InvalidIdentityException`,
 * never another exception, a warning or an accepted token that does not round-trip. Valid tokens are damaged by seeded
 * truncation, insertion, replacement, repetition and reversal.
 */

function validStateToken(FuzzInput $input): string
{
    return StateToken::of('storage-'.$input->int(0, 9), 'panel-'.$input->int(0, 9), 'inc-'.$input->int(0, 99999), $input->int(0, 1 << 30), $input->int(0, 1 << 20),
        'fp-'.$input->int(0, 99999), $input->int(0, 50), $input->int(0, 1) === 0 ? null : $input->int(0, 1000))->toString();
}

function validCodeToken(FuzzInput $input): string
{
    return CodeStateToken::of('panel-'.$input->int(0, 9), 'build-'.$input->int(0, 99999), 'fp-'.$input->int(0, 99999))->toString();
}

/** @param callable(string): (StateToken|CodeStateToken) $parse */
function tokenProperties(int $seed, int $iterations, callable $parse, Closure $valid): void
{
    $accepted = 0;
    $generator = static function (FuzzInput $input) use ($valid): string {
        return match ($input->int(0, 2)) {
            0 => $input->string(),
            default => $input->damage($valid($input)),
        };
    };

    FuzzInput::run($seed, $iterations, static function (string $value, string $label) use ($parse, &$accepted): void {
        try {
            $token = $parse($value);
        } catch (InvalidIdentityException) {
            return;
        }
        $accepted++;

        // An accepted token re-reads from its own text to an equal token.
        expect($parse($token->toString())->equals($token))->toBeTrue("round trip: $label");
    }, $generator);

    // Undamaged tokens are always accepted: the generator reaches the accepted branch.
    $input = new FuzzInput($seed);

    for ($i = 0; $i < min($iterations, 200); $i++) {
        $text = $valid($input);
        expect($parse($text)->toString())->toBe($text, "valid token seed=$seed #$i");
    }
}

it('reads state tokens or refuses them with a domain exception', function (int $seed): void {
    tokenProperties($seed, FuzzInput::iterations(), StateToken::fromString(...), validStateToken(...));
})->with([1, 2, 3]);

it('reads code state tokens or refuses them with a domain exception', function (int $seed): void {
    tokenProperties($seed, FuzzInput::iterations(), CodeStateToken::fromString(...), validCodeToken(...));
})->with([1, 2, 3]);

it('keeps the token properties over deep iterations', function (int $seed): void {
    tokenProperties($seed, FuzzInput::iterations(FuzzInput::DEEP_ITERATIONS), StateToken::fromString(...), validStateToken(...));
    tokenProperties($seed, FuzzInput::iterations(FuzzInput::DEEP_ITERATIONS), CodeStateToken::fromString(...), validCodeToken(...));
})->with([11])->group('extended');
