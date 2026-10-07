<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Storage\DdlSnapshot;

it('matches actual engine columns indexes constraints and collations for all host key types', function (): void {
    DdlSnapshot::verify();
})->group('engines');
