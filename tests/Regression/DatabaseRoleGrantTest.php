<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Sources\Scoped\ScopedSourceWorld;

beforeEach(fn () => ScopedSourceWorld::seed());
afterEach(fn () => ScopedSourceWorld::reset());

it('a DB role grants literal and pattern permissions globally and in its exact assignment only', function (): void {
    ScopedSourceWorld::roleMatrix();
});
