<?php

declare(strict_types=1);

arch('package sources declare strict types')
    ->expect('AzGuard')
    ->toUseStrictTypes()
    ->ignoring('AzGuard\Tests');

arch('package sources contain no debug calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsedIn('AzGuard')
    ->ignoring('AzGuard\Tests');
