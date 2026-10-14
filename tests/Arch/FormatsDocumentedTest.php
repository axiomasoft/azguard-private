<?php

declare(strict_types=1);

/*
 * The documentation guard of the frozen machine formats: every identity pinned by the format fixtures appears as a code
 * span in `docs/reference/*.md`. The test reads the fixtures, never a copy, so a new identity cannot ship undocumented and
 * the docs follow the fixtures, not the other way round.
 */

const FORMATS_FIXTURES = __DIR__.'/../Fixtures/Formats/';

function formatsJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<string> the identities the fixtures pin, each as the exact text a code span must carry */
function formatIdentities(?array $fixtures = null): array
{
    $fixtures ??= [
        'exceptions' => formatsJson(FORMATS_FIXTURES.'exceptions.json'),
        'events' => formatsJson(FORMATS_FIXTURES.'events.json'),
        'audit-rows' => formatsJson(FORMATS_FIXTURES.'audit-rows.json'),
        'resources' => formatsJson(FORMATS_FIXTURES.'resources.json'),
        'config' => formatsJson(FORMATS_FIXTURES.'config-defaults.json'),
        'commands' => formatsJson(__DIR__.'/../Fixtures/Console/commands.json'),
        'doctor-checks' => formatsJson(FORMATS_FIXTURES.'doctor-checks.json'),
    ];

    $identities = [];

    foreach ($fixtures['exceptions']['exceptions'] as $name => $exception) {
        $identities[] = $name;

        if ($exception['code'] !== null) {
            $identities[] = $exception['code'];
        }
    }

    foreach ($fixtures['exceptions']['invalid_configuration_checks'] as $check) {
        $identities[] = 'invalid_configuration.'.$check;
    }

    foreach ($fixtures['events']['types'] as $type) {
        $identities[] = $type['type'];
    }

    foreach ($fixtures['events']['events'] as $event) {
        foreach (['envelope', 'data', 'tenant', 'actor', 'state'] as $group) {
            array_push($identities, ...$event[$group]);
        }
    }

    array_push($identities, ...$fixtures['audit-rows']['columns']);

    foreach ($fixtures['resources']['publish_tags'] as $tags) {
        array_push($identities, ...array_keys($tags));
    }

    $identities = array_merge(
        $identities,
        $fixtures['resources']['core_migrations']['basenames'],
        $fixtures['resources']['audit_migrations']['basenames'],
        [
            $fixtures['resources']['named_storage_migration'],
            $fixtures['resources']['named_audit_migration'],
            $fixtures['resources']['stub_publish_path'],
            $fixtures['resources']['queue_hidden_context_key'],
            $fixtures['resources']['system_actor_type']['constant'],
        ],
        array_keys($fixtures['resources']['middleware_aliases']),
        array_values($fixtures['resources']['plugin_ids']),
        $fixtures['resources']['storage_state_schema_keys'],
    );

    array_push($identities, ...$fixtures['config']['environment']);

    foreach (['azguard', 'azguard-filament'] as $file) {
        array_push($identities, ...formatConfigPaths($fixtures['config'][$file]));
    }

    foreach ($fixtures['commands'] as $name => $command) {
        $identities[] = $name;
        preg_match_all('/--[a-z][a-z-]*/', $command['synopsis'], $options);
        array_push($identities, ...$options[0]);
        array_push($identities, ...array_keys($command['exit_codes'] ?? []));
    }

    foreach ($fixtures['doctor-checks']['checks'] as $check) {
        $identities[] = $check['key'];
        array_push($identities, ...$check['finding_ids']['literal']);
    }

    array_push($identities, ...$fixtures['doctor-checks']['severities']);

    return array_values(array_unique($identities));
}

/**
 * Dotted key paths of a configuration default; a list or an empty map is a leaf, and a map whose keys are user data
 * (a storage name) is followed by its pinned default entry.
 *
 * @return list<string>
 */
function formatConfigPaths(array $defaults, string $prefix = ''): array
{
    $paths = [];

    foreach ($defaults as $key => $value) {
        $path = $prefix.$key;

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            array_push($paths, ...formatConfigPaths($value, $path.'.'));

            continue;
        }

        $paths[] = $path;
    }

    return $paths;
}

/** @return list<string> identities that are not a code span of the given documentation text (an option may carry `=`) */
function undocumentedIdentities(array $identities, string $documentation): array
{
    return array_values(array_filter(
        $identities,
        static fn (string $identity): bool => ! str_contains($documentation, '`'.$identity.'`')
            && ! (str_starts_with($identity, '--') && str_contains($documentation, '`'.$identity.'=`')),
    ));
}

function referenceDocumentation(): string
{
    $pages = glob(dirname(__DIR__, 2).'/docs/reference/*.md') ?: [];

    return implode("\n", array_map(static fn (string $page): string => (string) file_get_contents($page), $pages));
}

it('documents every pinned machine-format identity in docs/reference', function (): void {
    $missing = undocumentedIdentities(formatIdentities(), referenceDocumentation());

    expect($missing)->toBe([], 'identities missing from docs/reference/*.md: '.implode(', ', $missing));
});

it('reads a substantial identity set from the fixtures', function (): void {
    expect(count(formatIdentities()))->toBeGreaterThan(200);
});

it('names a fabricated identity as undocumented', function (): void {
    $fixtures = [
        'exceptions' => ['exceptions' => ['FabricatedException' => ['code' => 'fabricated_code']], 'invalid_configuration_checks' => []],
        'events' => ['types' => [['type' => 'fabricated.event']], 'events' => []],
        'audit-rows' => ['columns' => []],
        'resources' => [
            'publish_tags' => [], 'core_migrations' => ['basenames' => []], 'audit_migrations' => ['basenames' => []],
            'named_storage_migration' => 'x', 'named_audit_migration' => 'y', 'stub_publish_path' => 'z',
            'queue_hidden_context_key' => 'k', 'system_actor_type' => ['constant' => 'c'], 'middleware_aliases' => [],
            'plugin_ids' => [], 'storage_state_schema_keys' => [],
        ],
        'config' => ['environment' => ['FABRICATED_ENV'], 'azguard' => ['fabricated' => ['key' => 1]], 'azguard-filament' => []],
        'commands' => ['azguard:fabricated' => ['synopsis' => '[--fabricated-option]']],
        'doctor-checks' => ['checks' => [], 'severities' => []],
    ];

    $missing = undocumentedIdentities(formatIdentities($fixtures), referenceDocumentation());

    expect($missing)->toContain('FabricatedException', 'fabricated_code', 'fabricated.event', 'FABRICATED_ENV', 'fabricated.key', 'azguard:fabricated', '--fabricated-option');
});
