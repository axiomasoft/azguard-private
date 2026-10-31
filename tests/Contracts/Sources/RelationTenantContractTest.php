<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Sources\Scoped\ScopedSourceWorld;

it('keeps relation membership inside its tenant and context in the source contract lane', function (): void {
    ScopedSourceWorld::seed();

    try {
        ScopedSourceWorld::run('relation project membership and store owner');
    } finally {
        ScopedSourceWorld::reset();
    }
});
