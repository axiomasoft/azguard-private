<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Sources\Scoped\ScopedSourceWorld;

function exerciseRelationTenantMode(string $mode): void
{
    ScopedSourceWorld::seed();

    try {
        ScopedSourceWorld::relations($mode);
    } finally {
        ScopedSourceWorld::reset();
    }
}

it('keeps relation membership inside tenant and context in inherit mode', fn () => exerciseRelationTenantMode('inherit'));
it('keeps relation membership inside tenant and context in isolated mode', fn () => exerciseRelationTenantMode('isolated'));
