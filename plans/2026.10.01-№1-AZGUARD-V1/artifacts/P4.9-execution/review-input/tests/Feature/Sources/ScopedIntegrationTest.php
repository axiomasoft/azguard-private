<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Sources\Scoped\ScopedSourceWorld;

beforeEach(fn () => ScopedSourceWorld::seed());
afterEach(fn () => ScopedSourceWorld::reset());

it('authorizes actual scoped sources through the complete scalar pipeline', function (string $scenario): void {
    ScopedSourceWorld::run($scenario);
})->with(ScopedSourceWorld::CASES);
