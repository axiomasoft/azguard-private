<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/bin/api-compat.php';

/*
 * A published migration never changes (knowledge/qualification#upgrade): every migration file of the compatibility
 * baseline (the newest stable tag merged into HEAD, else the `candidate` of compatibility.json) exists at the same path
 * with the same bytes. A new migration file is allowed; a changed or removed one is a break, since an installed storage
 * has already run it.
 */

/** @return list<string> the migration paths of a file list (packages/<name>/.../database/migrations/*.php) */
function migrationPaths(array $files): array
{
    $paths = array_values(array_filter($files, static fn (string $path): bool => preg_match('#^packages/[^/]+/(?:.+/)?database/migrations/[^/]+\.php$#', $path) === 1));
    sort($paths);

    return $paths;
}

/**
 * @param  array<string, string>  $baseline  path => content at the baseline
 * @param  array<string, string|null>  $current  path => content now, null when the file is gone
 * @return list<string>
 */
function migrationDrift(array $baseline, array $current): array
{
    $drift = [];

    foreach ($baseline as $path => $content) {
        if (! array_key_exists($path, $current) || $current[$path] === null) {
            $drift[] = "removed: {$path}";
        } elseif ($current[$path] !== $content) {
            $drift[] = "changed: {$path}";
        }
    }

    return $drift;
}

it('keeps every migration of the compatibility baseline byte for byte', function (): void {
    $root = dirname(__DIR__, 2);
    $spec = json_decode((string) file_get_contents($root.'/compatibility.json'), true, flags: JSON_THROW_ON_ERROR);
    $baseline = compatBaseline($root, 'auto', $spec);
    $listing = compatGit($root, ['ls-tree', '-r', '--name-only', $baseline['sha'], '--', 'packages']);
    $paths = migrationPaths(preg_split('/\R/', trim((string) $listing), -1, PREG_SPLIT_NO_EMPTY) ?: []);

    expect($paths)->not->toBe([], 'the baseline '.$baseline['ref'].' has migrations');

    $then = [];
    $now = [];

    foreach ($paths as $path) {
        $then[$path] = (string) compatGit($root, ['show', $baseline['sha'].':'.$path]);
        $now[$path] = is_file($root.'/'.$path) ? (string) file_get_contents($root.'/'.$path) : null;
    }

    expect(migrationDrift($then, $now))->toBe([], 'a published migration changed since '.$baseline['ref'].': add a new migration instead');
});

it('reports a changed or removed migration and allows a new one', function (): void {
    $baseline = ['packages/core/database/migrations/2026_01_01_000000_a.php' => 'A', 'packages/core/database/migrations/2026_01_02_000000_b.php' => 'B'];

    expect(migrationDrift($baseline, $baseline))->toBe([])
        ->and(migrationDrift($baseline, [...$baseline, 'packages/core/database/migrations/2026_02_01_000000_c.php' => 'C']))->toBe([])
        ->and(migrationDrift($baseline, [...$baseline, 'packages/core/database/migrations/2026_01_01_000000_a.php' => 'A2']))->toBe(['changed: packages/core/database/migrations/2026_01_01_000000_a.php'])
        ->and(migrationDrift($baseline, ['packages/core/database/migrations/2026_01_01_000000_a.php' => 'A']))->toBe(['removed: packages/core/database/migrations/2026_01_02_000000_b.php']);
});

it('finds migrations in the package folders and in the module folders only', function (): void {
    expect(migrationPaths([
        'packages/core/database/migrations/2026_10_01_000000_x.php',
        'packages/core/modules/audit/database/migrations/2026_10_20_000000_y.php',
        'packages/core/database/migrations/README.md',
        'packages/core/src/database/migrations.php',
        'tests/database/migrations/z.php',
    ]))->toBe([
        'packages/core/database/migrations/2026_10_01_000000_x.php',
        'packages/core/modules/audit/database/migrations/2026_10_20_000000_y.php',
    ]);
});
