<?php

declare(strict_types=1);

/*
 * Test ownership (PLAN1 P3.11, target-structure#tests): a module's tests travel with the module, so a file under
 * tests/Modules/<module> references no other module — neither its namespace nor the test namespace of its fixtures.
 * A file outside tests/Modules references no module either, except the files of tests/Arch/Fixtures/module-consumers.json:
 * the core tests, fixtures and snapshots that exercise a module through an application as a consumer does. That list is
 * exact: a new reference fails, and a listed file that no longer references the module is stale and fails too, so it
 * only shrinks. A test of one module that references another is split; a consumer (an architecture test, the base test
 * case, a world fixture) may name several modules.
 */

/** @return list<string> module namespace names from the core PSR-4 map, e.g. Scopes, Audit, DevTools */
function azguardTestModules(): array
{
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $modules = [];

    foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
        if (str_starts_with((string) $path, 'modules/')) {
            $modules[] = trim(substr((string) $namespace, strlen('AzGuard\\')), '\\');
        }
    }

    return $modules;
}

/**
 * The modules a source mentions by namespace (`AzGuard\<Module>\`, or `AzGuard\Tests\Modules\<Module>\` for a fixture
 * of its tests), in the plain and the string-escaped form.
 *
 * @param  list<string>  $modules
 * @return list<string>
 */
function azguardModuleReferences(string $code, array $modules): array
{
    $names = implode('|', array_map('preg_quote', $modules));
    preg_match_all('/AzGuard\\\\{1,2}(?:Tests\\\\{1,2}Modules\\\\{1,2})?('.$names.')\\\\/', $code, $matches);
    $found = array_values(array_unique($matches[1]));
    sort($found);

    return $found;
}

/** @return array<string, list<string>> test file (relative to tests/) => modules it references */
function azguardTestFileReferences(): array
{
    $root = dirname(__DIR__);
    $modules = azguardTestModules();
    $references = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $found = azguardModuleReferences((string) file_get_contents($file->getPathname()), $modules);

        if ($found !== []) {
            $references[substr($file->getPathname(), strlen($root) + 1)] = $found;
        }
    }
    ksort($references);

    return $references;
}

it('finds a module by its namespace in every form a test can name it', function (): void {
    $code = <<<'PHP'
        <?php
        use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
        use AzGuard\Tests\Modules\Audit\Fixtures\Journal;
        $plugin = 'AzGuard\\DevTools\\DevToolsServiceProvider';
        $core = AzGuard\Contracts\Scopes\TenantMembership::class.AzGuard\Panels\Panel::class.AzGuard\Tests\Fixtures\Panels\User::class;
        PHP;

    expect(azguardTestModules())->toBe(['Scopes', 'Audit', 'DevTools'])
        ->and(azguardModuleReferences($code, azguardTestModules()))->toBe(['Audit', 'DevTools', 'Scopes'])
        ->and(azguardModuleReferences('<?php use AzGuard\Contracts\Scopes\TenantMembership; use AzGuard\Audits\X;', azguardTestModules()))->toBe([]);
});

/**
 * @param  array<string, list<string>>  $references
 * @return array<string, list<string>> the files of tests/Modules/<module> that reference another module
 */
function azguardCrossedModuleTests(array $references): array
{
    $crossed = [];

    foreach ($references as $file => $referenced) {
        if (preg_match('#^Modules/([^/]+)/#', $file, $owner) !== 1) {
            continue;
        }

        $foreign = array_values(array_filter($referenced, static fn (string $module): bool => $module !== $owner[1]));

        if ($foreign !== []) {
            $crossed[$file] = $foreign;
        }
    }

    return $crossed;
}

it('sees a module test that references another module and lets it reference its own', function (): void {
    expect(azguardCrossedModuleTests([
        'Modules/Scopes/Feature/AThingTest.php' => ['Audit', 'Scopes'],
        'Modules/Scopes/Fixtures/B.php' => ['Scopes'],
        'Modules/Audit/Feature/CTest.php' => ['DevTools'],
        'Feature/Gate/DTest.php' => ['Audit', 'Scopes'],
    ]))->toBe(['Modules/Scopes/Feature/AThingTest.php' => ['Audit'], 'Modules/Audit/Feature/CTest.php' => ['DevTools']]);
});

it('keeps the tests of a module free of every other module', function (): void {
    expect(azguardTestModules())->toContain('Scopes', 'Audit', 'DevTools')
        ->and(azguardCrossedModuleTests(azguardTestFileReferences()))->toBe([], 'A module test references another module: split it or move it out of tests/Modules.');
});

it('keeps every test outside tests/Modules free of modules except the pinned consumers', function (): void {
    $pinned = json_decode((string) file_get_contents(__DIR__.'/Fixtures/module-consumers.json'), true, flags: JSON_THROW_ON_ERROR)['files'];
    $outside = array_filter(azguardTestFileReferences(), static fn (string $file): bool => ! str_starts_with($file, 'Modules/'), ARRAY_FILTER_USE_KEY);

    $new = array_diff_key($outside, $pinned);
    $stale = array_diff_key($pinned, $outside);
    $changed = array_filter($outside, static fn (array $referenced, string $file): bool => isset($pinned[$file]) && $pinned[$file] !== $referenced, ARRAY_FILTER_USE_BOTH);

    expect($new)->toBe([], 'A test outside tests/Modules references a module: move it to tests/Modules/<module>, or pin it as a consumer.')
        ->and($stale)->toBe([], 'A pinned consumer no longer references the module it is pinned for: remove it from module-consumers.json.')
        ->and($changed)->toBe([], 'A pinned consumer references other modules than pinned.');
});
