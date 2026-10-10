<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\SourceScan;

const AZGUARD_DEBUG_CALLS = ['dd', 'dump', 'ray', 'var_dump', 'print_r'];

arch('package sources declare strict types')
    ->expect('AzGuard')
    ->toUseStrictTypes()
    ->ignoring('AzGuard\Tests');

it('finds debug calls but not methods, static calls or declarations with the same name', function (): void {
    $code = <<<'PHP'
        <?php
        namespace AzGuard\X;
        dump($a);
        \var_dump($b);
        $c = print_r($d, true);
        DD($e);
        $f->dump();
        $g?->ray();
        Debug::dd();
        $h = new Ray();
        function ray(): void {}
        // dump($i);
        $j = 'dd($k)';
        PHP;

    expect(SourceScan::functionCalls($code, AZGUARD_DEBUG_CALLS))->toBe(['dump', 'var_dump', 'print_r', 'dd']);
});

it('keeps package sources free of debug calls', function (): void {
    expect(SourceScan::callsIn(SourceScan::files(), AZGUARD_DEBUG_CALLS))->toBe([]);
});
