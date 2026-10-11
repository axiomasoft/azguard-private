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

/*
 * I1 baseline over every production root, the module roots (`packages/core/modules/<module>/src`) included: Pest arch
 * reads only the namespaces composer autoloads, a module root is autoloaded from P3.
 */
it('finds a production file without strict types, in any root', function (): void {
    $directory = sys_get_temp_dir().'/azguard-strict-types-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $loose = $directory.'/Loose.php';
    $strict = $directory.'/Strict.php';
    file_put_contents($loose, "<?php\nnamespace AzGuard\\Audit;\nfinal class Loose {}\n");
    file_put_contents($strict, "<?php\n\ndeclare(strict_types=1);\n\nnamespace AzGuard\\Audit;\nfinal class Strict {}\n");

    try {
        expect(SourceScan::withoutStrictTypes([$loose, $strict]))->toBe([$loose]);
    } finally {
        unlink($loose);
        unlink($strict);
        rmdir($directory);
    }
});

it('declares strict types in every production file', function (): void {
    expect(SourceScan::withoutStrictTypes(SourceScan::files()))->toBe([]);
});
