<?php

declare(strict_types=1);

/*
 * tests/zones.json routes tests to zones (Pest groups, `composer test:changed`). The map must not go stale: a source
 * directory or a test file outside every zone would never be selected by `test:changed`, and a path that no longer
 * exists would silently select nothing.
 */

/** @return array{zones: array<string, array{src: list<string>, tests: list<string>, uses: list<string>}>, database: list<string>, global: list<string>} */
function azguardZoneMap(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/zones.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps every path of the zone map', function (): void {
    $root = dirname(__DIR__, 2);
    $map = azguardZoneMap();
    $missing = [];

    foreach ($map['zones'] as $name => $zone) {
        foreach ($zone['src'] as $path) {
            file_exists("{$root}/{$path}") || $missing[] = "{$name}: {$path}";
        }

        foreach ($zone['tests'] as $path) {
            file_exists("{$root}/tests/{$path}") || $missing[] = "{$name}: tests/{$path}";
        }
    }

    foreach ($map['database'] as $path) {
        file_exists("{$root}/tests/{$path}") || $missing[] = "database: tests/{$path}";
    }

    expect($missing)->toBe([]);
});

it('assigns every source directory of the packages to a zone', function (): void {
    $root = dirname(__DIR__, 2);
    $assigned = array_merge(...array_column(azguardZoneMap()['zones'], 'src'));
    $unassigned = [];

    foreach (['packages/core/src', 'packages/filament/src'] as $package) {
        foreach (glob("{$root}/{$package}/*") ?: [] as $path) {
            $relative = substr($path, strlen($root) + 1);

            if (! in_array($relative, $assigned, true) && ! in_array($package, $assigned, true)) {
                $unassigned[] = $relative;
            }
        }
    }

    expect($unassigned)->toBe([]);
});

it('assigns every test file to a zone', function (): void {
    $tests = dirname(__DIR__);
    $assigned = array_merge(...array_column(azguardZoneMap()['zones'], 'tests'));
    $unassigned = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tests, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($tests) + 1);

        if (! str_ends_with($relative, 'Test.php') || str_contains($relative, '/Fixtures/') || str_starts_with($relative, 'Fixtures/')) {
            continue;
        }

        $zoned = array_filter($assigned, fn (string $path): bool => $relative === $path || str_starts_with($relative, $path.'/'));

        if ($zoned === []) {
            $unassigned[] = $relative;
        }
    }

    expect($unassigned)->toBe([]);
});

it('lets a zone use only existing zones and never itself', function (): void {
    $zones = azguardZoneMap()['zones'];
    $broken = [];

    foreach ($zones as $name => $zone) {
        foreach ($zone['uses'] as $used) {
            if (! isset($zones[$used]) || $used === $name) {
                $broken[] = "{$name} uses {$used}";
            }
        }
    }

    expect($broken)->toBe([]);
});
