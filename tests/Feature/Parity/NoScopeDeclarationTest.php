<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Baseline070\Persistence\Dataset;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Parity\NoScopeWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * I7 harness (PLAN1 P1.3): without a scope declaration non-global requests are denied, scoped rows never apply to
 * global requests, non-global writes are rejected, and revocation and expiry still work without consulting scope
 * behavior — over the populated 0.7.0 `default` database of P1.1, on every driver.
 *
 * `AssignmentScopePolicy::none()` is the 0.7.0 equivalent of both "no scope declaration" and "scope plugin removed":
 * 0.7.0 has no scope plugin or declaration slot; the policy a panel passes to `scopes()` is its declaration, `none()`
 * is the empty one (mode `none`, no accepted types), and a panel built without `scopes()` gets the same. Rebuilding
 * panel `crm` of the dataset with `none()` over its stored project rows is therefore the database a removed scope
 * module leaves behind.
 *
 * A case 0.7.0 fails is listed in tests/Fixtures/Parity/i7-allowlist.json with its observed behavior and owner P2:
 * a listed case must still fail exactly as recorded (a fixed one is a stale entry), any other case must hold.
 */

beforeEach(fn () => NoScopeWorld::restore());

afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
    Dataset::releaseUlids();
});

function i7Allowlist(): array
{
    $entries = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Parity/i7-allowlist.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_column($entries, null, 'case');
}

it('I7 holds over the populated 0.7.0 default database or fails exactly as allowlisted', function (string $case): void {
    [$holds, $observed] = NoScopeWorld::cases()[$case]();
    $listed = i7Allowlist()[$case] ?? null;

    if ($listed === null) {
        expect($holds)->toBeTrue('I7 case "'.$case.'" fails at 0.7.0: '.$observed);
    } else {
        expect($holds)->toBeFalse('I7 case "'.$case.'" now holds; delete its stale entry from i7-allowlist.json')
            ->and($observed)->toBe($listed['observed']);
    }
})->with(array_keys(NoScopeWorld::cases()));

it('I7 allowlists only known cases of 0.7.0, each owned by P2', function (): void {
    $entries = i7Allowlist();

    expect(array_diff(array_keys($entries), array_keys(NoScopeWorld::cases())))->toBe([]);

    foreach ($entries as $case => $entry) {
        expect($entry)->toHaveKeys(['case', 'observed', 'owner_phase'], 'I7 allowlist entry "'.$case.'" is incomplete')
            ->and($entry['owner_phase'])->toBe('P2');
    }
});
