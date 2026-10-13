<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\AutoloadOwnership;
use AzGuard\Tests\Arch\BoundaryScan;
use AzGuard\Tests\Arch\Ownership;
use AzGuard\Tests\Arch\SourceScan;

/*
 * I5 (PLAN1 P1.4), report mode until P3 exit: every production FQCN under packages/<name>/src and
 * packages/core/modules/<module>/src has exactly one PSR-4 owner among the `autoload` entries of packages/<name>/composer.json,
 * the authoritative classmap built from the sources has no type declared in two files, and `src/Scopes`, `src/Audit`,
 * `src/DevTools` and `src/Plugins/Audit` are reported. The current violations must equal tests/Arch/Allowlists/i5.json
 * exactly; both lists are empty at P3 exit, when the rule blocks as it is. The optimized-autoload consumer run is the
 * consumer job of PLAN1 P1.5.
 */

/**
 * Builds a throw-away repository, runs the scan against an empty oracle and removes the tree.
 *
 * @param  array<string, string>  $files  path relative to the root => contents
 * @return list<string> violations as "<kind> <file> <reference>"
 */
function autoloadScanOf(array $files): array
{
    $root = sys_get_temp_dir().'/azguard-autoload-'.bin2hex(random_bytes(4));

    try {
        foreach ($files as $path => $contents) {
            is_dir(dirname($root.'/'.$path)) || mkdir(dirname($root.'/'.$path), 0777, true);
            file_put_contents($root.'/'.$path, $contents);
        }

        return array_map(
            static fn (array $violation): string => implode(' ', [$violation['kind'], $violation['file'], $violation['reference']]),
            AutoloadOwnership::violations(Ownership::of([]), root: $root),
        );
    } finally {
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($tree as $node) {
            $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname());
        }
        rmdir($root);
    }
}

function autoloadComposer(array $psr4): string
{
    return json_encode(['autoload' => ['psr-4' => $psr4]], JSON_THROW_ON_ERROR);
}

function autoloadClass(string $namespace, string $name): string
{
    return "<?php\nnamespace {$namespace};\nfinal class {$name} {}\n";
}

it('reads the types a file declares, not an anonymous class, a class constant or a conditional twin', function (): void {
    $code = <<<'PHP'
        <?php
        namespace AzGuard\X;
        interface Contract {}
        trait Concern {}
        enum Kind: string { case A = 'a'; }
        if (true) { final class Twin {} } else { final class Twin {} }
        final readonly class Value { public function make(): object { return new class {}; } public function name(): string { return self::class.Other::class; } }
        PHP;

    expect(AutoloadOwnership::declaredTypes($code))->toBe(['AzGuard\X\Contract', 'AzGuard\X\Concern', 'AzGuard\X\Kind', 'AzGuard\X\Twin', 'AzGuard\X\Twin', 'AzGuard\X\Value']);
});

it('reads each declaration in the namespace in force at it', function (): void {
    $code = "<?php\nnamespace AzGuard\\Panels {\n}\nnamespace AzGuard\\Wrong {\nfinal class PanelBuilder {}\n}\nnamespace {\nfinal class Global_ {}\n}\n";

    expect(AutoloadOwnership::declaredTypes($code))->toBe(['AzGuard\Wrong\PanelBuilder', 'Global_']);
});

it('reports a type whose declared namespace is not the one of its file', function (): void {
    expect(autoloadScanOf([
        'packages/core/composer.json' => autoloadComposer(['AzGuard\\' => 'src/']),
        'packages/core/src/Panels/PanelBuilder.php' => "<?php\nnamespace AzGuard\\Panels {\n}\nnamespace AzGuard\\Wrong {\nfinal class PanelBuilder {}\n}\n",
    ]))->toBe(['no-owner packages/core/src/Panels/PanelBuilder.php AzGuard\Wrong\PanelBuilder']);
});

it('accepts a type that exactly one PSR-4 entry loads, in core, in a module root and in a sibling package', function (): void {
    expect(autoloadScanOf([
        'packages/core/composer.json' => autoloadComposer(['AzGuard\\' => 'src/', 'AzGuard\\Audit\\' => 'modules/audit/src/']),
        'packages/filament/composer.json' => autoloadComposer(['AzGuard\\Filament\\' => 'src/']),
        'packages/core/src/Panels/Panel.php' => autoloadClass('AzGuard\Panels', 'Panel'),
        'packages/core/modules/audit/src/AuditPlugin.php' => autoloadClass('AzGuard\Audit', 'AuditPlugin'),
        'packages/filament/src/Page.php' => autoloadClass('AzGuard\Filament', 'Page'),
    ]))->toBe([]);
});

it('reports a type no entry loads: a module root nobody declared and a file in the wrong directory', function (): void {
    expect(autoloadScanOf([
        'packages/core/composer.json' => autoloadComposer(['AzGuard\\' => 'src/']),
        'packages/core/modules/audit/src/AuditPlugin.php' => autoloadClass('AzGuard\Audit', 'AuditPlugin'),
        'packages/core/src/Panels/Elsewhere.php' => autoloadClass('AzGuard\Catalog', 'Elsewhere'),
    ]))->toBe([
        'no-owner packages/core/modules/audit/src/AuditPlugin.php AzGuard\Audit\AuditPlugin',
        'no-owner packages/core/src/Panels/Elsewhere.php AzGuard\Catalog\Elsewhere',
    ]);
});

it('reports a type that two entries load', function (): void {
    expect(autoloadScanOf([
        'packages/core/composer.json' => autoloadComposer(['AzGuard\\' => 'src/', 'AzGuard\\Panels\\' => 'src/Panels/']),
        'packages/core/src/Panels/Panel.php' => autoloadClass('AzGuard\Panels', 'Panel'),
    ]))->toBe([
        'several-owners packages/core/src/Panels/Panel.php AzGuard\Panels\Panel',
    ]);
});

it('reports a type declared in two files, once, and not a conditional declaration in one file', function (): void {
    expect(autoloadScanOf([
        'packages/core/composer.json' => autoloadComposer(['AzGuard\\' => 'src/', 'AzGuard\\Audit\\' => 'modules/audit/src/']),
        'packages/core/src/Audit/AuditPlugin.php' => autoloadClass('AzGuard\Audit', 'AuditPlugin'),
        'packages/core/modules/audit/src/AuditPlugin.php' => autoloadClass('AzGuard\Audit', 'AuditPlugin'),
        'packages/core/src/Panels/Twin.php' => "<?php\nnamespace AzGuard\\Panels;\nif (true) { final class Twin {} } else { final class Twin {} }\n",
    ]))->toBe([
        'directory packages/core/src/Audit Audit',
        'duplicate-class packages/core/modules/audit/src/AuditPlugin.php AzGuard\Audit\AuditPlugin',
    ]);
});

it('reports the directories the transition removes, in every production root', function (): void {
    expect(autoloadScanOf([
        'packages/core/composer.json' => autoloadComposer(['AzGuard\\' => 'src/']),
        'packages/core/src/Scopes/Policy.php' => autoloadClass('AzGuard\Scopes', 'Policy'),
        'packages/core/src/Plugins/Audit/AuditPlugin.php' => autoloadClass('AzGuard\Plugins\Audit', 'AuditPlugin'),
        'packages/core/src/DevTools/Make.php' => autoloadClass('AzGuard\DevTools', 'Make'),
        'packages/core/src/Plugins/Plugin.php' => autoloadClass('AzGuard\Plugins', 'Plugin'),
    ]))->toBe([
        'directory packages/core/src/DevTools DevTools',
        'directory packages/core/src/Plugins/Audit Plugins/Audit',
        'directory packages/core/src/Scopes Scopes',
    ]);
});

it('I5 (report mode, blocking after P3): every production type has one autoload owner', function (): void {
    $difference = BoundaryScan::compare(AutoloadOwnership::violations(Ownership::fromOracle()), BoundaryScan::allowlist('i5'));

    expect($difference['new'])->toBe([], 'new I5 violations: add no allowlist entry, fix the autoload ownership')
        ->and($difference['stale'])->toBe([], 'stale I5 allowlist entries: delete them from tests/Arch/Allowlists/i5.json');
});

it('keeps the I5 allowlist empty once the module files moved to their roots (P3.4)', function (): void {
    expect(BoundaryScan::allowlist('i5'))->toBe([]);
});

it('holds an authoritative classmap of every production type without a duplicate', function (): void {
    $root = dirname(__DIR__, 2);
    $map = AutoloadOwnership::classMap(SourceScan::files(), $root);

    expect(count($map))->toBeGreaterThan(400)
        ->and(array_filter($map, static fn (array $paths): bool => count($paths) > 1))->toBe([])
        ->and($map['AzGuard\Panels\PanelBuilder'])->toBe(['packages/core/src/Panels/PanelBuilder.php']);
});

it('has the same types in the composer authoritative classmap as in the sources, each in its file', function (): void {
    $authoritative = AutoloadOwnership::authoritativeClassMap();
    $declared = array_map(static fn (array $paths): string => $paths[0], AutoloadOwnership::classMap(SourceScan::files(), dirname(__DIR__, 2)));
    $unreachable = array_column(array_filter(
        AutoloadOwnership::violations(Ownership::fromOracle()),
        static fn (array $violation): bool => in_array($violation['kind'], ['no-owner', 'several-owners'], true),
    ), 'reference');

    expect(count($authoritative))->toBeGreaterThan(400)
        ->and(array_diff_key($authoritative, $declared))->toBe([])
        ->and(array_diff_key($declared, $authoritative, array_flip($unreachable)))->toBe([]);

    foreach ($authoritative as $type => $file) {
        expect($file)->toBe($declared[$type], $type);
    }
});

it('lets composer skip a type in the wrong directory and refuse a type two files declare', function (): void {
    $root = sys_get_temp_dir().'/azguard-authoritative-'.bin2hex(random_bytes(4));
    $write = static function (string $path, string $code) use ($root): void {
        is_dir(dirname($root.'/'.$path)) || mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $code);
    };

    try {
        $write('packages/core/composer.json', json_encode(['autoload' => ['psr-4' => ['AzGuard\\' => 'src/']]], JSON_THROW_ON_ERROR));
        $write('packages/core/src/Panels/Panel.php', autoloadClass('AzGuard\Panels', 'Panel'));
        $write('packages/core/src/Panels/Elsewhere.php', autoloadClass('AzGuard\Catalog', 'Elsewhere'));

        expect(AutoloadOwnership::authoritativeClassMap($root))->toBe(['AzGuard\Panels\Panel' => 'packages/core/src/Panels/Panel.php']);

        $write('packages/core/composer.json', json_encode(['autoload' => ['psr-4' => ['AzGuard\\' => ['src/', 'lib/']]]], JSON_THROW_ON_ERROR));
        $write('packages/core/lib/Panels/Panel.php', autoloadClass('AzGuard\Panels', 'Panel'));

        expect(fn () => AutoloadOwnership::authoritativeClassMap($root))->toThrow(RuntimeException::class);
    } finally {
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($tree as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
});
