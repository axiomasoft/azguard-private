<?php

declare(strict_types=1);

use AzGuard\Tests\Modules\Scopes\Fixtures\ActiveProjects;
use AzGuard\Tests\Modules\Scopes\Fixtures\Broken\DeletingFilter;
use AzGuard\Tests\Modules\Scopes\Fixtures\Broken\DriftingFilter;
use AzGuard\Tests\Modules\Scopes\Fixtures\Broken\OrderingFilter;
use AzGuard\Tests\Modules\Scopes\Fixtures\Broken\WritingFilter;
use AzGuard\Tests\Modules\Scopes\Fixtures\EligibilityWorld;
use AzGuard\Tests\Modules\Scopes\Fixtures\FilterProbe;
use PHPUnit\Framework\AssertionFailedError;

/*
 * A suite that cannot be failed proves nothing: each case runs one test of the filter suite on a filter that keeps the
 * guarantee, which passes, and on one that breaks it, which fails.
 */

beforeEach(function (): void {
    EligibilityWorld::tables();
    DriftingFilter::$calls = 0;
});

it('fails the filter suite on a filter that breaks a guarantee and passes it on one that keeps it', function (string $method, Closure $broken): void {
    (new FilterProbe(static fn () => new ActiveProjects))->run($method);

    expect(fn () => (new FilterProbe($broken))->run($method))->toThrow(AssertionFailedError::class);
})->with([
    'is refused by the guard when it orders' => ['filterPassesTheGuardOfTheModule', static fn () => new OrderingFilter],
    'is refused by the guard when it deletes' => ['filterPassesTheGuardOfTheModule', static fn () => new DeletingFilter],
    'answers one runtime differently' => ['filterIsPureForOneRuntime', static fn () => new DriftingFilter],
    'writes while it narrows' => ['filterWritesNothingWhileItNarrows', static fn () => new WritingFilter],
]);
