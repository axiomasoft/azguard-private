<?php

declare(strict_types=1);

use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Sources\Relation\RelationSource;
use Symfony\Component\Process\Process;

/**
 * Each cell names an executable source-specific scenario in this required lane. Conditional cells identify a
 * supported scenario or a typed refusal; they do not assert that every arbitrary configuration is queryable.
 * The guard also locks the status so a new claim cannot silently reuse an old scenario.
 *
 * @return array<string, array<string, array{string, string, string}>>
 */
function builtInCapabilityCoverage(): array
{
    $folder = ['QueryParityContractTest.php', 'matches scalar batch and exact visibility for a folder automatic role'];
    $database = ['QueryParityContractTest.php', 'matches scalar batch and exact visibility for a scoped database grant'];
    $relation = ['RelationSourceContractTest.php', 'test_queryable_membership_matches_scalar_batch_and_exact_visibility'];
    $gate = ['GateSourceContractTest.php', 'test_gate_scalar_and_batch_agree_and_visibility_refuses_an_unqueryable_policy'];
    $filament = ['FilamentSourceContractTest.php', 'test_definitions_participate_in_scalar_batch_and_filament_checks'];
    $unstable = ['StableCacheCapabilityTest.php', 'built-in unsupported stable-cache sources cannot qualify for stable authority reuse'];
    $conditional = static fn (array $ref): array => ['conditional', ...$ref];
    $supported = static fn (array $ref): array => ['supported', ...$ref];
    $unsupported = static fn (array $ref): array => ['unsupported', ...$ref];

    return [
        'folder' => [
            'scalar' => $supported($folder), 'batch' => $supported($folder), 'exact_visibility' => $conditional($folder),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['QueryParityContractTest.php', 'enforces tenant membership for folder automatic roles']),
            'filament' => $conditional(['FilamentSourceContractTest.php', 'test_folder_automatic_role_grants_a_filament_resource_only_to_its_members']),
        ],
        'folder_automatic_role' => [
            'scalar' => $supported(['QueryParityContractTest.php', 'refuses exact visibility for a folder automatic role without a selection adapter']),
            'batch' => $supported(['QueryParityContractTest.php', 'refuses exact visibility for a folder automatic role without a selection adapter']),
            'exact_visibility' => $unsupported(['QueryParityContractTest.php', 'refuses exact visibility for a folder automatic role without a selection adapter']),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['QueryParityContractTest.php', 'enforces tenant membership for folder automatic roles']),
            'filament' => $conditional(['FilamentSourceContractTest.php', 'test_folder_automatic_role_grants_a_filament_resource_only_to_its_members']),
        ],
        'database' => [
            'scalar' => $supported($database), 'batch' => $supported($database), 'exact_visibility' => $conditional($database),
            'stable_cache' => $conditional(['DatabaseCacheContractTest.php', 'reuses stable database authority and invalidates it after grant and revoke']),
            'tenant_inheritance' => $conditional(['QueryParityContractTest.php', 'applies a tenant-wide database grant according to context inheritance without crossing tenants']),
            'filament' => $conditional($filament),
        ],
        'relation' => [
            'scalar' => $supported($relation), 'batch' => $supported($relation), 'exact_visibility' => $conditional($relation),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['RelationTenantContractTest.php', 'keeps relation membership inside its tenant and context in the source contract lane']),
            'filament' => $conditional(['FilamentSourceContractTest.php', 'test_relation_membership_controls_filament_record_and_visible_query']),
        ],
        'gate' => [
            'scalar' => $supported($gate), 'batch' => $supported($gate), 'exact_visibility' => $conditional($gate),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['GateSourceContractTest.php', 'test_tenant_membership_still_gates_native_policy_decisions']),
            'filament' => $conditional(['FilamentSourceContractTest.php', 'test_gate_source_decides_filament_resource_definitions']),
        ],
        'filament' => [
            'scalar' => $supported($filament), 'batch' => $supported($filament),
            'exact_visibility' => $conditional(['FilamentSourceContractTest.php', 'test_relation_membership_controls_filament_record_and_visible_query']),
            'stable_cache' => $unsupported($unstable),
            'tenant_inheritance' => $conditional(['FilamentSourceContractTest.php', 'test_filament_definitions_respect_tenant_and_membership_boundaries']),
            'filament' => $conditional($filament),
        ],
    ];
}

/** @return list<string> */
function builtInCapabilityCoverageErrors(array $matrix, array $coverage): array
{
    $errors = [];
    $classes = [
        'folder' => FolderSource::class,
        'folder_automatic_role' => FolderSource::class,
        'database' => DatabaseSource::class,
        'relation' => RelationSource::class,
        'gate' => GateSource::class,
        'filament' => FilamentSource::class,
    ];
    $profiles = array_keys($classes);

    if (array_diff($profiles, array_keys($coverage)) || array_diff(array_keys($coverage), $profiles)) {
        $errors[] = 'Built-in source profiles differ from the coverage map.';
    }
    // Variants may share a class; an entirely new built-in class needs a map entry.
    $coveredClasses = array_values(array_unique($classes));
    foreach ($matrix['sources'] ?? [] as $profile => $row) {
        $class = $row['class'] ?? '';
        $builtIn = str_starts_with($class, 'AzGuard\\Sources\\') || str_starts_with($class, 'AzGuard\\Filament\\Sources\\');

        if ($builtIn && (! in_array($class, $coveredClasses, true) || ! array_key_exists($profile, $classes))) {
            $errors[] = "$profile adds a built-in source profile without mandatory coverage.";
        }
    }
    foreach ($profiles as $profile) {
        if (($matrix['sources'][$profile]['class'] ?? null) !== $classes[$profile]) {
            $errors[] = "$profile changed its source class without revising its tests.";
        }
        $cells = $matrix['sources'][$profile]['matrix'] ?? [];
        $entries = $coverage[$profile] ?? [];
        foreach (array_unique([...array_keys($cells), ...array_keys($entries)]) as $cell) {
            if (! isset($cells[$cell], $entries[$cell]) || count($entries[$cell]) !== 3) {
                $errors[] = "$profile.$cell is missing a matrix cell or mandatory test.";

                continue;
            }
            [$status, $file, $name] = $entries[$cell];

            if ($status !== $cells[$cell]['status']) {
                $errors[] = "$profile.$cell changed status without revising its test.";
            }
            $path = __DIR__.'/'.$file;
            $text = is_file($path) ? file_get_contents($path) : false;
            $declaration = str_starts_with($name, 'test_') ? 'function '.$name.'(' : "it('".$name."'";

            if (! is_string($text) || ! str_contains($text, $declaration)) {
                $errors[] = "$profile.$cell references a missing required-lane test: $file::$name.";
            }
        }
    }

    return $errors;
}

/** @return list<string> */
function builtInCapabilityExecutionErrors(array $coverage): array
{
    $scenarios = [];
    foreach ($coverage as $cells) {
        foreach ($cells as [, $file, $name]) {
            $scenarios[$file.'::'.$name] = [$file, $name];
        }
    }

    $errors = [];
    foreach ($scenarios as $id => [$file, $name]) {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/vendor/bin/pest', __DIR__.'/'.$file,
            '--filter='.$name, '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-risky', '--fail-on-empty-test-suite'], dirname(__DIR__, 3));
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            $errors[] = "$id did not pass: ".$process->getOutput().$process->getErrorOutput();
        }
    }

    return $errors;
}

it('requires an executable source-specific contract for every built-in matrix cell', function (): void {
    $matrix = json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/reference/source-capabilities.json'), true, flags: JSON_THROW_ON_ERROR);
    $coverage = builtInCapabilityCoverage();
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->toBe([]);
    expect(builtInCapabilityExecutionErrors($coverage))->toBe([]);
});

it('fails the matrix guard when a built-in cell is removed or changes status', function (): void {
    $matrix = json_decode(file_get_contents(dirname(__DIR__, 3).'/docs/reference/source-capabilities.json'), true, flags: JSON_THROW_ON_ERROR);
    $coverage = builtInCapabilityCoverage();
    unset($coverage['relation']['filament']);
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->not->toBeEmpty();
    $coverage = builtInCapabilityCoverage();
    $matrix['sources']['relation']['matrix']['stable_cache']['status'] = 'conditional';
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->not->toBeEmpty();
    $matrix['sources']['new_source'] = ['class' => 'AzGuard\\Sources\\NewSource', 'matrix' => []];
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->toContain('new_source adds a built-in source profile without mandatory coverage.');
    $matrix['sources']['new_source']['class'] = FolderSource::class;
    expect(builtInCapabilityCoverageErrors($matrix, $coverage))->toContain('new_source adds a built-in source profile without mandatory coverage.');
});

it('fails the execution guard when a referenced scenario does not run', function (): void {
    $coverage = ['folder' => ['scalar' => ['supported', 'QueryParityContractTest.php', 'nonexistent matrix scenario']]];
    expect(builtInCapabilityExecutionErrors($coverage))->not->toBeEmpty();
});
