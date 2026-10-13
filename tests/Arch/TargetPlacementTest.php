<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\Ownership;
use AzGuard\Tests\Arch\SourceScan;

/*
 * Phase exit of P3: the tree under each production root is the target tree of the ownership oracle
 * (tests/Arch/Fixtures/target-map.json, D14). Every scan resolves a type by its FQCN, so a row that stayed at its old
 * FQCN would pass them all; this test compares places instead of names:
 *
 *  - each row that is not retired is declared at its `target_path` with its target FQCN (each part of a split row);
 *  - no production file declares the old FQCN of a moved, renamed, split or retired row;
 *  - no production file stands outside the target paths of the oracle.
 *
 * All differences are reported at once. A row that a later P3 item creates is named in TARGET_PLACEMENT_PENDING with that
 * item; the list only shrinks (a file that exists makes its entry stale and fails) and is empty at P3 exit.
 */

/** @var array<string, string> target path => the P3 item that creates it */
const TARGET_PLACEMENT_PENDING = [];

/**
 * @param  list<array<string, mixed>>  $rows
 * @param  string  $root  repository root the files are read from
 * @param  array<string, string>  $pending  target path => the item that creates it
 * @return list<string> sorted differences
 */
function targetPlacementDifferences(array $rows, string $root, array $pending = []): array
{
    $differences = [];
    $targets = [];
    $superseded = [];

    foreach ($rows as $row) {
        $paths = array_map('trim', explode(' + ', (string) ($row['target_path'] ?? '')));
        $types = array_map('trim', explode(' + ', (string) ($row['target'] ?? '')));

        if (($row['action'] ?? null) !== 'retire' && ($row['target_path'] ?? null) !== null) {
            if (count($paths) !== count($types)) {
                $differences[] = 'malformed row '.$row['current'].': '.count($paths).' paths for '.count($types).' types';

                continue;
            }

            foreach ($paths as $index => $path) {
                $targets[$path] = true;
                $file = $root.'/'.$path;

                if (isset($pending[$path])) {
                    is_file($file) && $differences[] = 'stale-pending '.$path.' exists; remove it from TARGET_PLACEMENT_PENDING ('.$pending[$path].')';

                    continue;
                }

                if (! is_file($file)) {
                    $differences[] = 'missing '.$path.' ('.$types[$index].')';

                    continue;
                }
                $declared = Ownership::declaredType((string) file_get_contents($file));

                if ($declared !== $types[$index]) {
                    $differences[] = 'wrong-type '.$path.' declares '.($declared ?? 'nothing').', expected '.$types[$index];
                }
            }
        }

        if (($row['current'] ?? null) !== null && ! in_array($row['current'], $types, true)) {
            $superseded[strtolower((string) $row['current'])] = (string) $row['current'];
        }
    }

    foreach (SourceScan::files(root: $root) as $file) {
        $path = substr($file, strlen($root) + 1);
        $declared = Ownership::declaredType((string) file_get_contents($file));

        if ($declared !== null && isset($superseded[strtolower($declared)])) {
            $differences[] = 'superseded '.$path.' declares '.$declared;
        }

        if (! isset($targets[$path])) {
            $differences[] = 'unplaced '.$path;
        }
    }
    sort($differences);

    return $differences;
}

/**
 * @param  array<string, string>  $files  path relative to the root => code
 * @return string the throw-away root
 */
function targetPlacementTree(array $files): string
{
    $root = sys_get_temp_dir().'/azguard-placement-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $code) {
        is_dir(dirname($root.'/'.$path)) || mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $code);
    }

    return $root;
}

function targetPlacementClean(string $root): void
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

it('places every type of the oracle at its target path with its target FQCN and nothing else', function (): void {
    $map = json_decode((string) file_get_contents(__DIR__.'/Fixtures/target-map.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(targetPlacementDifferences($map['rows'], dirname(__DIR__, 2), TARGET_PLACEMENT_PENDING))->toBe([]);
});

it('reports a misplaced, missing, superseded and unplaced file at once (non-vacuity)', function (): void {
    $root = targetPlacementTree([
        'packages/core/src/Panels/Internal/Moved.php' => "<?php\nnamespace AzGuard\\Panels;\nfinal class Moved {}\n",
        'packages/core/src/Panels/Old.php' => "<?php\nnamespace AzGuard\\Panels;\nfinal class Old {}\n",
        'packages/core/src/Panels/Stray.php' => "<?php\nnamespace AzGuard\\Panels;\nfinal class Stray {}\n",
        'packages/core/src/Kept.php' => "<?php\nnamespace AzGuard;\nfinal class Kept {}\n",
    ]);
    $rows = [
        ['current' => 'AzGuard\\Panels\\Old', 'current_path' => 'packages/core/src/Panels/Old.php', 'target' => 'AzGuard\\Panels\\Internal\\Old', 'target_path' => 'packages/core/src/Panels/Internal/Old.php', 'action' => 'move'],
        ['current' => 'AzGuard\\Panels\\Moved', 'current_path' => 'packages/core/src/Panels/Moved.php', 'target' => 'AzGuard\\Panels\\Internal\\Moved', 'target_path' => 'packages/core/src/Panels/Internal/Moved.php', 'action' => 'move'],
        ['current' => 'AzGuard\\Kept', 'current_path' => 'packages/core/src/Kept.php', 'target' => 'AzGuard\\Kept', 'target_path' => 'packages/core/src/Kept.php', 'action' => 'keep'],
        ['current' => 'AzGuard\\Gone', 'current_path' => 'packages/core/src/Gone.php', 'target' => null, 'target_path' => null, 'action' => 'retire'],
    ];

    try {
        expect(targetPlacementDifferences($rows, $root))->toBe([
            'missing packages/core/src/Panels/Internal/Old.php (AzGuard\\Panels\\Internal\\Old)',
            'superseded packages/core/src/Panels/Internal/Moved.php declares AzGuard\\Panels\\Moved',
            'superseded packages/core/src/Panels/Old.php declares AzGuard\\Panels\\Old',
            'unplaced packages/core/src/Panels/Old.php',
            'unplaced packages/core/src/Panels/Stray.php',
            'wrong-type packages/core/src/Panels/Internal/Moved.php declares AzGuard\\Panels\\Moved, expected AzGuard\\Panels\\Internal\\Moved',
        ]);
    } finally {
        targetPlacementClean($root);
    }
});

it('accepts a split row by the parts of its target and path', function (): void {
    $root = targetPlacementTree([
        'packages/core/src/A/Internal/One.php' => "<?php\nnamespace AzGuard\\A\\Internal;\nfinal class One {}\n",
        'packages/core/src/B/Internal/Two.php' => "<?php\nnamespace AzGuard\\B\\Internal;\nfinal class Two {}\n",
    ]);
    $rows = [['current' => 'AzGuard\\Both', 'current_path' => 'packages/core/src/Both.php', 'target' => 'AzGuard\\A\\Internal\\One + AzGuard\\B\\Internal\\Two',
        'target_path' => 'packages/core/src/A/Internal/One.php + packages/core/src/B/Internal/Two.php', 'action' => 'split']];

    try {
        expect(targetPlacementDifferences($rows, $root))->toBe([]);
    } finally {
        targetPlacementClean($root);
    }
});

it('lets a pending row be absent and flags it once the file exists (non-vacuity)', function (): void {
    $path = 'packages/core/src/Later.php';
    $rows = [['current' => null, 'current_path' => null, 'target' => 'AzGuard\\Later', 'target_path' => $path, 'action' => 'new']];
    $absent = targetPlacementTree(['packages/core/src/Other.php' => "<?php\nnamespace AzGuard;\nfinal class Other {}\n"]);
    $present = targetPlacementTree([$path => "<?php\nnamespace AzGuard;\nfinal class Later {}\n"]);

    try {
        expect(targetPlacementDifferences($rows, $absent, [$path => 'P9.9']))->toBe(['unplaced packages/core/src/Other.php'])
            ->and(targetPlacementDifferences($rows, $present, [$path => 'P9.9']))->toBe(['stale-pending '.$path.' exists; remove it from TARGET_PLACEMENT_PENDING (P9.9)'])
            ->and(targetPlacementDifferences($rows, $absent))->toBe(['missing '.$path.' (AzGuard\\Later)', 'unplaced packages/core/src/Other.php']);
    } finally {
        targetPlacementClean($absent);
        targetPlacementClean($present);
    }
});
