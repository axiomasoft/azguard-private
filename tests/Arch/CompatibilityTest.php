<?php

declare(strict_types=1);

/*
 * I18 (D26): the compatibility comparison rejects every intentional break and accepts the allowed additions.
 *
 * The current manifests and fixtures are the baseline; each case applies one in-memory mutation to a copy and runs the
 * comparison of bin/api-compat.php (required without running its CLI). A break case asserts its exact rule ids, so it
 * fails when its rule is removed; a control asserts that the mutation stays compatible. bin/compat-drill.sh proves the
 * same on real code patches against a stable tag.
 */

require_once dirname(__DIR__, 2).'/bin/api-compat.php';

/** Every rule id of knowledge/p4-execution#compatibility: each has a break case below. */
const COMPAT_RULE_IDS = [
    'artifact.removed',
    'type.removed', 'type.publication_changed', 'type.shape_changed',
    'member.removed', 'member.visibility_narrowed', 'member.return_changed',
    'parameter.removed', 'parameter.reordered', 'parameter.added_required', 'parameter.added_overridable',
    'parameter.renamed', 'parameter.type_changed', 'parameter.default_changed',
    'named.stance_changed', 'implement.method_added',
    'enum.case_removed', 'enum.value_changed', 'enum.closed_case_added', 'enum.closed_reopened',
    'constant.value_changed', 'contracts.changed', 'facade.removed', 'facade.changed', 'roots.changed',
    'format.removed', 'format.changed', 'format.ddl_unmigrated',
];

/**
 * The current artifacts of compatibility.json, read once.
 *
 * @return array<string, array{kind: string, path: string, data: array<mixed>}>
 */
function compatTestArtifacts(): array
{
    static $artifacts = null;

    if ($artifacts === null) {
        $root = dirname(__DIR__, 2);
        $list = compatArtifactList(json_decode((string) file_get_contents($root.'/compatibility.json'), true), 'compatibility.json');
        $artifacts = compatCollect($list, static fn (string $path): ?string => is_file($root.'/'.$path) ? (string) file_get_contents($root.'/'.$path) : null, true, 'working tree');
    }

    return $artifacts;
}

/**
 * Compares the current artifacts with a copy in which one artifact is mutated.
 *
 * @param  callable(array<mixed>): array<mixed>  $mutate
 * @return array{breaks: list<array<string, mixed>>, additions: list<array<string, mixed>>}
 */
function compatMutated(string $artifact, callable $mutate): array
{
    $baseline = compatTestArtifacts();
    $current = $baseline;
    $current[$artifact]['data'] = $mutate($current[$artifact]['data']);

    return compatCompare($baseline, $current);
}

/**
 * A mutation of one type of a manifest.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 * @return Closure(array<mixed>): array<mixed>
 */
function compatOnType(string $fqcn, callable $mutate): Closure
{
    return static function (array $manifest) use ($fqcn, $mutate): array {
        $found = false;

        foreach ($manifest['classes'] as $index => $type) {
            if ($type['name'] === $fqcn) {
                $manifest['classes'][$index] = $mutate($type);
                $found = true;
            }
        }
        expect($found)->toBeTrue("{$fqcn} is in the manifest");

        return $manifest;
    };
}

/**
 * A mutation of one method of a type (or of the facade when `$fqcn` is null).
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 * @return Closure(array<mixed>): array<mixed>
 */
function compatOnMethod(?string $fqcn, string $method, callable $mutate): Closure
{
    $inList = static function (array $methods) use ($method, $mutate): array {
        $names = array_column($methods, 'name');
        expect($names)->toContain($method);
        $methods[array_search($method, $names, true)] = $mutate($methods[array_search($method, $names, true)]);

        return $methods;
    };

    if ($fqcn === null) {
        return static function (array $manifest) use ($inList): array {
            $manifest['facade'] = $inList($manifest['facade']);

            return $manifest;
        };
    }

    return compatOnType($fqcn, static function (array $type) use ($inList): array {
        $type['methods'] = $inList($type['methods']);

        return $type;
    });
}

/**
 * @param  array<string, mixed>  $method
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function compatWithParameter(array $method, array $overrides): array
{
    $method['parameters'][] = ['name' => 'extra', 'type' => '?string', 'byRef' => false, 'variadic' => false, 'hasDefault' => true, 'default' => 'NULL', ...$overrides];

    return $method;
}

/**
 * @param  array{breaks: list<array<string, mixed>>}  $result
 * @return list<string>
 */
function compatRuleIds(array $result): array
{
    $rules = array_values(array_unique(array_column($result['breaks'], 'rule')));
    sort($rules);

    return $rules;
}

/** @return array<string, mixed> */
function compatTestMethod(string $name, bool $abstract = false): array
{
    return ['name' => $name, 'visibility' => 'public', 'static' => false, 'abstract' => $abstract, 'final' => false, 'parameters' => [],
        'return' => 'void', 'namedArguments' => true, 'contracts' => []];
}

const COMPAT_ENTRY = 'AzGuard\\AzGuard';
const COMPAT_PLUGIN = 'AzGuard\\Contracts\\Plugins\\Plugin';
const COMPAT_TENANT_MODE = 'AzGuard\\Tenancy\\TenantMode';
const COMPAT_EVENT_TYPE = 'AzGuard\\Events\\EventType';
const COMPAT_GRANT_FILTER = 'AzGuard\\Changes\\GrantFilter';
const COMPAT_BASE_ROLE = 'AzGuard\\Roles\\BaseRole';
const COMPAT_ROWS_WRITER = 'AzGuard\\Contracts\\Changes\\OwnedRowsWriter';
const COMPAT_ACCESS_EVENT = 'AzGuard\\Events\\AccessEvent';

it('uses publications and enum classes the catalogue assumes', function (): void {
    $types = compatTypes(['api.core' => compatTestArtifacts()['api.core']['data']]);
    $describe = static fn (string $fqcn): array => [$types[$fqcn][1]['publication'], $types[$fqcn][1]['kind'], $types[$fqcn][1]['final'], $types[$fqcn][1]['enum'] ?? null];

    expect($describe(COMPAT_ENTRY))->toBe(['api', 'class', true, null])
        ->and($describe(COMPAT_PLUGIN))->toBe(['spi-implement', 'interface', false, null])
        ->and($describe(COMPAT_TENANT_MODE))->toBe(['api', 'enum', true, 'closed'])
        ->and($describe(COMPAT_EVENT_TYPE))->toBe(['api', 'enum', true, 'open'])
        ->and($describe(COMPAT_GRANT_FILTER))->toBe(['api', 'class', true, null])
        ->and($describe(COMPAT_BASE_ROLE))->toBe(['spi-implement', 'class', false, null])
        ->and($describe(COMPAT_ROWS_WRITER))->toBe(['spi-call', 'interface', false, null])
        ->and($describe(COMPAT_ACCESS_EVENT))->toBe(['api', 'class', false, null]);
});

it('finds nothing when nothing changed', function (): void {
    $artifacts = compatTestArtifacts();

    expect($artifacts)->toHaveCount(22)
        ->and(compatCompare($artifacts, $artifacts))->toBe(['breaks' => [], 'additions' => []]);
});

it('rejects an intentional break with its rule id', function (string $artifact, Closure $mutate, array $rules): void {
    expect(compatRuleIds(compatMutated($artifact, $mutate)))->toBe($rules);
})->with([
    // PHP surface
    'type removed' => ['api.core', static function (array $m): array {
        $m['classes'] = array_values(array_filter($m['classes'], static fn (array $t): bool => $t['name'] !== COMPAT_GRANT_FILTER));

        return $m;
    }, ['type.removed']],
    'publication changed' => ['api.core', compatOnType(COMPAT_PLUGIN, static fn (array $t): array => [...$t, 'publication' => 'spi-call']), ['type.publication_changed']],
    'since changed' => ['api.core', compatOnType(COMPAT_GRANT_FILTER, static fn (array $t): array => [...$t, 'since' => '1.1']), ['type.publication_changed']],
    'final added' => ['api.core', compatOnType(COMPAT_ACCESS_EVENT, static fn (array $t): array => [...$t, 'final' => true]), ['type.shape_changed']],
    'interface removed' => ['api.core', compatOnType('AzGuard\\Exceptions\\AmbiguousPanelException', static fn (array $t): array => [...$t, 'interfaces' => ['Throwable']]), ['type.shape_changed']],
    'method removed' => ['api.core', compatOnType(COMPAT_ENTRY, static function (array $t): array {
        $t['methods'] = array_values(array_filter($t['methods'], static fn (array $m): bool => $m['name'] !== 'check'));

        return $t;
    }), ['member.removed']],
    'method became static' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static fn (array $m): array => [...$m, 'static' => true]), ['member.visibility_narrowed']],
    'return changed' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static fn (array $m): array => [...$m, 'return' => '?bool']), ['member.return_changed']],
    'parameter removed' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static fn (array $m): array => [...$m, 'parameters' => array_slice($m['parameters'], 0, 3)]), ['parameter.removed']],
    'parameters reordered' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static function (array $m): array {
        [$m['parameters'][0], $m['parameters'][1]] = [$m['parameters'][1], $m['parameters'][0]];

        return $m;
    }), ['parameter.reordered']],
    'required parameter added' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static fn (array $m): array => compatWithParameter($m, ['hasDefault' => false, 'default' => null])), ['parameter.added_required']],
    'optional parameter added to an implemented method' => ['api.core', compatOnMethod(COMPAT_PLUGIN, 'register', static fn (array $m): array => compatWithParameter($m, [])), ['parameter.added_overridable']],
    'optional parameter added to an overridable method' => ['api.core', compatOnMethod(COMPAT_ACCESS_EVENT, 'subject', static fn (array $m): array => compatWithParameter($m, [])), ['parameter.added_overridable']],
    'parameter renamed' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static function (array $m): array {
        $m['parameters'][0]['name'] = 'who';

        return $m;
    }), ['parameter.renamed']],
    'parameter type changed' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static function (array $m): array {
        $m['parameters'][1]['type'] = 'string';

        return $m;
    }), ['parameter.type_changed']],
    'default changed' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static function (array $m): array {
        $m['parameters'][3]['default'] = "'web'";

        return $m;
    }), ['parameter.default_changed']],
    'default removed' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static function (array $m): array {
        $m['parameters'][3]['hasDefault'] = false;
        unset($m['parameters'][3]['default']);

        return $m;
    }), ['parameter.default_changed']],
    'method named arguments withdrawn' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static fn (array $m): array => [...$m, 'namedArguments' => false]), ['named.stance_changed']],
    'type named arguments withdrawn' => ['api.core', compatOnType(COMPAT_GRANT_FILTER, static fn (array $t): array => [...$t, 'namedArguments' => false]), ['named.stance_changed']],
    'method added to an implemented interface' => ['api.core', compatOnType(COMPAT_PLUGIN, static fn (array $t): array => [...$t, 'methods' => [...$t['methods'], compatTestMethod('boot')]]), ['implement.method_added']],
    'abstract method added to a base class' => ['api.core', compatOnType(COMPAT_BASE_ROLE, static fn (array $t): array => [...$t, 'methods' => [...$t['methods'], compatTestMethod('describe', abstract: true)]]), ['implement.method_added']],
    'enum case removed' => ['api.core', compatOnType(COMPAT_TENANT_MODE, static fn (array $t): array => [...$t, 'cases' => array_slice($t['cases'], 0, 1)]), ['enum.case_removed']],
    'enum value changed' => ['api.core', compatOnType(COMPAT_TENANT_MODE, static function (array $t): array {
        $t['cases'][0]['value'] = 'nothing';

        return $t;
    }), ['enum.value_changed']],
    'closed enum case added' => ['api.core', compatOnType(COMPAT_TENANT_MODE, static fn (array $t): array => [...$t, 'cases' => [...$t['cases'], ['name' => 'Optional', 'value' => 'optional']]]), ['enum.closed_case_added']],
    'closed enum reopened' => ['api.core', compatOnType(COMPAT_TENANT_MODE, static fn (array $t): array => [...$t, 'enum' => 'open']), ['enum.closed_reopened']],
    'constant value changed' => ['api.core', compatOnType(COMPAT_GRANT_FILTER, static function (array $t): array {
        foreach ($t['constants'] as $i => $constant) {
            if ($constant['name'] === 'MAX_LIMIT') {
                $t['constants'][$i]['value'] = 1;
            }
        }

        return $t;
    }), ['constant.value_changed']],
    'method contracts changed' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'actingAs', static fn (array $m): array => [...$m, 'contracts' => []]), ['contracts.changed']],
    'facade method removed' => ['api.core', static function (array $m): array {
        $m['facade'] = array_values(array_filter($m['facade'], static fn (array $f): bool => $f['name'] !== 'check'));

        return $m;
    }, ['facade.removed']],
    'facade method changed' => ['api.core', compatOnMethod(null, 'check', static fn (array $m): array => [...$m, 'return' => 'mixed']), ['facade.changed']],
    'module root removed' => ['api.core', static function (array $m): array {
        unset($m['roots'][array_key_last($m['roots'])]);

        return $m;
    }, ['roots.changed']],
    // Formats
    'exception code' => ['exceptions', static function (array $f): array {
        $f['exceptions']['AmbiguousPanelException']['code'] = 'panel_ambiguous';

        return $f;
    }, ['format.changed']],
    'event payload key' => ['events', static function (array $f): array {
        $f['events']['role.granted']['data'] = array_values(array_diff($f['events']['role.granted']['data'], ['role']));

        return $f;
    }, ['format.removed']],
    'event state key' => ['events', static function (array $f): array {
        $f['events']['role.granted']['state'] = array_values(array_diff($f['events']['role.granted']['state'], ['fingerprint']));

        return $f;
    }, ['format.removed']],
    'journal row column' => ['audit-rows', static function (array $f): array {
        $f['columns'] = array_values(array_diff($f['columns'], ['actor_reason']));

        return $f;
    }, ['format.removed']],
    'journaled event type' => ['audit-journaled-types', static fn (array $f): array => array_slice($f, 1), ['format.removed']],
    'publish tag' => ['resources', static function (array $f): array {
        $f['publish_tags']['audit'] = ['azguard-audit' => $f['publish_tags']['audit']['azguard-audit-migrations']];

        return $f;
    }, ['format.removed']],
    'migration basename' => ['resources', static function (array $f): array {
        $f['core_migrations']['basenames'] = ['2026_10_01_000000_create_azguard_tables'];

        return $f;
    }, ['format.removed']],
    'reserved plugin prefix' => ['reserved-plugin-prefix', static fn (array $f): array => ['azguard/host-plugin accepted' => true], ['format.changed']],
    'config default value' => ['config-defaults', static function (array $f): array {
        $f['azguard']['defaults']['cache']['ttl'] = 60;

        return $f;
    }, ['format.changed']],
    'config default JSON type' => ['config-defaults', static function (array $f): array {
        $f['azguard']['defaults']['cache']['ttl'] = '3600';

        return $f;
    }, ['format.changed']],
    'config list default' => ['config-defaults', static function (array $f): array {
        $f['azguard-filament']['abilities'][] = 'export';

        return $f;
    }, ['format.changed']],
    'environment name' => ['config-defaults', static function (array $f): array {
        $f['environment'] = ['AZGUARD_DB_CONNECTION'];

        return $f;
    }, ['format.removed']],
    'retired key' => ['config-defaults', static function (array $f): array {
        $f['retired'] = [];

        return $f;
    }, ['format.removed']],
    'command option removed' => ['commands', static function (array $f): array {
        $f['azguard:audit:prune']['synopsis'] = '[--panel [PANEL]] [--before [BEFORE]]';

        return $f;
    }, ['format.removed']],
    'command option mode' => ['commands', static function (array $f): array {
        $f['azguard:audit:prune']['synopsis'] = '[--panel PANEL] [--before [BEFORE]] [--force]';

        return $f;
    }, ['format.changed']],
    'command required argument added' => ['commands', static function (array $f): array {
        $f['azguard:audit:migration']['synopsis'] = '<storage> <table>';

        return $f;
    }, ['format.changed']],
    'command exit code' => ['commands', static function (array $f): array {
        $f['azguard:audit:prune']['exit_codes']['production without --force'] = 1;

        return $f;
    }, ['format.changed']],
    'doctor finding id' => ['doctor-checks', static function (array $f): array {
        $f['checks']['AzGuard\\Diagnostics\\Internal\\Checks\\CacheStore']['finding_ids']['literal'] = ['cache.store_missing'];

        return $f;
    }, ['format.removed']],
    'doctor check key' => ['doctor-checks', static function (array $f): array {
        $f['checks']['AzGuard\\Diagnostics\\Internal\\Checks\\CacheStore']['key'] = 'cache.driver';

        return $f;
    }, ['format.removed']],
    'doctor output field' => ['doctor', static function (array $f): array {
        $f['healthy']['finding_fields'] = array_values(array_diff($f['healthy']['finding_fields'], ['scope']));

        return $f;
    }, ['format.removed']],
    'explain key' => ['explain', static function (array $f): array {
        unset($f['allow']['decision']['reason']);

        return $f;
    }, ['format.removed']],
    'serializable type' => ['serializable-types', static function (array $f): array {
        $f['types'] = array_slice($f['types'], 1);

        return $f;
    }, ['format.removed']],
    'DDL column type' => ['ddl.pgsql', static function (array $f): array {
        $f['string']['permissions']['columns'][1]['type'] = 'character varying(128)';

        return $f;
    }, ['format.changed']],
    'unmigrated DDL column' => ['ddl.sqlite', static function (array $f): array {
        $f['string']['permissions']['columns'][] = [...$f['string']['permissions']['columns'][1], 'name' => 'note'];

        return $f;
    }, ['format.ddl_unmigrated']],
    'unmigrated audit index' => ['ddl.mysql.audit', static function (array $f): array {
        $f['string']['audit_log']['indexes'][] = [...$f['string']['audit_log']['indexes'][0], 'name' => 'azg_al_extra'];

        return $f;
    }, ['format.ddl_unmigrated']],
]);

it('rejects a removed artifact', function (): void {
    $baseline = compatTestArtifacts();
    $current = $baseline;
    unset($current['explain']);

    expect(compatRuleIds(compatCompare($baseline, $current)))->toBe(['artifact.removed']);
});

it('has a break case for every rule id', function (): void {
    $source = (string) file_get_contents(__FILE__);
    preg_match_all("/\\['((?:[a-z]+)\\.[a-z_]+)'\\]\\]/", $source, $cases);
    $covered = array_unique([...$cases[1], 'artifact.removed']);
    sort($covered);
    $rules = COMPAT_RULE_IDS;
    sort($rules);

    expect($covered)->toBe($rules);

    // Every rule id the comparator can emit is in the list.
    preg_match_all("/compatBreak\\('([a-z_.]+)'/", (string) file_get_contents(dirname(__DIR__, 2).'/bin/api-compat.php'), $emitted);
    expect(array_diff(array_unique($emitted[1]), COMPAT_RULE_IDS))->toBe([]);
});

it('accepts an allowed change', function (string $artifact, Closure $mutate, ?string $addition): void {
    $result = compatMutated($artifact, $mutate);

    expect($result['breaks'])->toBe([]);

    if ($addition === null) {
        expect($result['additions'])->toBe([]);
    } else {
        expect(array_column($result['additions'], 'rule'))->toContain($addition);
    }
})->with([
    'method on a final api class' => ['api.core', compatOnType(COMPAT_GRANT_FILTER, static fn (array $t): array => [...$t, 'methods' => [...$t['methods'], compatTestMethod('describe')]]), 'member.added'],
    'method on a spi-call interface' => ['api.core', compatOnType(COMPAT_ROWS_WRITER, static fn (array $t): array => [...$t, 'methods' => [...$t['methods'], compatTestMethod('delete')]]), 'member.added'],
    'optional trailing parameter of a final method' => ['api.core', compatOnMethod(COMPAT_ENTRY, 'check', static fn (array $m): array => compatWithParameter($m, [])), 'parameter.added_optional'],
    'case of an open enum' => ['api.core', compatOnType(COMPAT_EVENT_TYPE, static fn (array $t): array => [...$t, 'cases' => [...$t['cases'], ['name' => 'PanelReset', 'value' => 'panel.reset']]]), 'enum.open_case_added'],
    'open enum closed' => ['api.core', compatOnType(COMPAT_EVENT_TYPE, static fn (array $t): array => [...$t, 'enum' => 'closed']), 'enum.closed'],
    'new supported type' => ['api.core', static function (array $m): array {
        $m['classes'][] = [...$m['classes'][0], 'name' => 'AzGuard\\Changes\\GrantSummary'];

        return $m;
    }, 'type.added'],
    'new constant' => ['api.core', compatOnType(COMPAT_GRANT_FILTER, static fn (array $t): array => [...$t, 'constants' => [...$t['constants'], ['name' => 'MIN_LIMIT', 'visibility' => 'public', 'final' => false, 'value' => 1]]]), 'member.added'],
    'parameter renamed on an implemented interface' => ['api.core', compatOnMethod(COMPAT_PLUGIN, 'register', static function (array $m): array {
        $m['parameters'][0]['name'] = 'target';

        return $m;
    }), null],
    'root directory moved' => ['api.core', static function (array $m): array {
        $m['roots'][array_key_last($m['roots'])] = '../module/src/';

        return $m;
    }, null],
    'new event type' => ['events', static function (array $f): array {
        $f['types'][] = ['case' => 'PanelReset', 'type' => 'panel.reset'];
        $f['events']['panel.reset'] = $f['events']['role.granted'];

        return $f;
    }, 'format.added'],
    'new event data key' => ['events', static function (array $f): array {
        $f['events']['role.granted']['data'][] = 'note';

        return $f;
    }, 'format.added'],
    'new optional command option' => ['commands', static function (array $f): array {
        $f['azguard:audit:prune']['synopsis'] .= ' [--chunk [CHUNK]]';

        return $f;
    }, 'format.added'],
    'new optional command argument' => ['commands', static function (array $f): array {
        $f['azguard:audit:migration']['synopsis'] = '<storage> [<table>]';

        return $f;
    }, 'format.added'],
    'new exit-code label' => ['commands', static function (array $f): array {
        $f['azguard:audit:prune']['exit_codes']['nothing to prune'] = 0;

        return $f;
    }, 'format.added'],
    'new config key with a default' => ['config-defaults', static function (array $f): array {
        $f['azguard']['defaults']['cache']['lock_seconds'] = 10;

        return $f;
    }, 'format.added'],
    'renamed internal doctor check class with the same key' => ['doctor-checks', static function (array $f): array {
        $f['checks']['AzGuard\\Diagnostics\\Internal\\Checks\\CacheStoreCheck'] = $f['checks']['AzGuard\\Diagnostics\\Internal\\Checks\\CacheStore'];
        unset($f['checks']['AzGuard\\Diagnostics\\Internal\\Checks\\CacheStore']);

        return $f;
    }, null],
    'new doctor finding id' => ['doctor-checks', static function (array $f): array {
        $f['checks']['AzGuard\\Diagnostics\\Internal\\Checks\\CacheStore']['finding_ids']['literal'][] = 'cache.store_slow';

        return $f;
    }, 'format.added'],
    'new serializable type' => ['serializable-types', static function (array $f): array {
        $f['types'][] = ['fqcn' => 'AzGuard\\Changes\\GrantSummary', 'kinds' => ['readonly']];

        return $f;
    }, 'format.added'],
    'new DDL variant' => ['ddl.pgsql', static function (array $f): array {
        $f['ulid32'] = $f['ulid'];

        return $f;
    }, 'format.added'],
]);

it('accepts named arguments promised where they were not', function (): void {
    $baseline = compatTestArtifacts();
    $current = $baseline;
    $baseline['api.core']['data'] = compatOnMethod(COMPAT_ENTRY, 'check', static fn (array $m): array => [...$m, 'namedArguments' => false])($baseline['api.core']['data']);
    $baseline['api.core']['data'] = compatOnType(COMPAT_GRANT_FILTER, static fn (array $t): array => [...$t, 'namedArguments' => false])($baseline['api.core']['data']);

    $result = compatCompare($baseline, $current);

    expect($result['breaks'])->toBe([])
        ->and(array_column($result['additions'], 'locator'))->toBe(['AzGuard\\AzGuard::check()', 'AzGuard\\Changes\\GrantFilter#namedArguments'])
        ->and(array_unique(array_column($result['additions'], 'rule')))->toBe(['named.stance_opened']);
});

it('accepts a DDL addition that comes with a new migration basename', function (): void {
    $baseline = compatTestArtifacts();
    $current = $baseline;
    $current['ddl.sqlite']['data']['string']['permissions']['columns'][] = [...$baseline['ddl.sqlite']['data']['string']['permissions']['columns'][1], 'name' => 'note'];
    $current['resources']['data']['core_migrations']['basenames'][] = '2027_01_01_000000_add_note_to_azguard_permissions';

    $result = compatCompare($baseline, $current);

    expect($result['breaks'])->toBe([])
        ->and(array_column($result['additions'], 'locator'))->toContain('string/permissions/columns/note', 'core_migrations/basenames/2027_01_01_000000_add_note_to_azguard_permissions');
});

it('matches artifacts by id, so a moved fixture is no break', function (): void {
    $content = (string) file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/Formats/explain.json');
    $baseline = compatCollect(['explain' => ['path' => 'tests/Fixtures/Formats/explain.json', 'kind' => 'format']], static fn (string $path): ?string => $path === 'tests/Fixtures/Formats/explain.json' ? $content : null, true, 'baseline');
    $current = compatCollect(['explain' => ['path' => 'tests/Fixtures/Explain/explain.json', 'kind' => 'format']], static fn (string $path): ?string => $path === 'tests/Fixtures/Explain/explain.json' ? $content : null, true, 'current');

    expect(compatCompare($baseline, $current))->toBe(['breaks' => [], 'additions' => []]);
});

it('skips a baseline artifact that did not exist yet, but requires the ones its own list names', function (): void {
    $list = ['explain' => ['path' => 'tests/Fixtures/Formats/explain.json', 'kind' => 'format']];

    expect(compatCollect($list, static fn (string $path): ?string => null, false, 'baseline'))->toBe([])
        ->and(static fn (): array => compatCollect($list, static fn (string $path): ?string => null, true, 'baseline'))->toThrow(CompatError::class, 'artifact explain');
});

it('selects the newest stable tag of major 1 or later', function (array $tags, ?string $expected): void {
    expect(compatSelectTag($tags))->toBe($expected);
})->with([
    'only 0.x tags' => [['v0.3.0', 'v0.7.0'], null],
    'pre-releases' => [['v0.7.0', 'v1.0.0-rc.1', 'v1.0.0-beta'], null],
    'no tags' => [[], null],
    'versions, not strings' => [['v0.7.0', 'v1.0.0', 'v1.2.0', 'v1.10.0', 'v2.0.0-rc.1'], 'v1.10.0'],
    'malformed tags' => [['1.5.0', 'v1.5', 'release-1.6.0', 'v1.0.0'], 'v1.0.0'],
    'next major' => [['v1.9.3', 'v2.0.0'], 'v2.0.0'],
]);

it('parses Symfony console synopses', function (): void {
    expect(compatParseSynopsis('[-p|--panel [PANEL]] [--only ONLY] [--force] [--ansi|--no-ansi] [--] <subject> [<permission>...]'))->toBe([
        'arguments' => [
            'subject' => ['position' => 0, 'required' => true, 'array' => false],
            'permission' => ['position' => 1, 'required' => false, 'array' => true],
        ],
        'options' => [
            'panel' => ['shortcut' => 'p', 'value' => 'optional', 'negatable' => false],
            'only' => ['shortcut' => null, 'value' => 'required', 'negatable' => false],
            'force' => ['shortcut' => null, 'value' => 'none', 'negatable' => false],
            'ansi' => ['shortcut' => null, 'value' => 'none', 'negatable' => true],
        ],
    ])
        ->and(compatParseSynopsis('<storage> [<table> [<column>]]')['arguments'])->toBe([
            'storage' => ['position' => 0, 'required' => true, 'array' => false],
            'table' => ['position' => 1, 'required' => false, 'array' => false],
            'column' => ['position' => 2, 'required' => false, 'array' => false],
        ])
        ->and(static fn (): array => compatParseSynopsis('{subject}'))->toThrow(CompatError::class);

    foreach (compatTestArtifacts()['commands']['data'] as $command) {
        compatParseSynopsis($command['synopsis']);
    }
});

it('reports a break with the rule, artifact, locator and both values', function (): void {
    $result = compatMutated('api.core', compatOnMethod(COMPAT_ENTRY, 'check', static function (array $m): array {
        $m['parameters'][0]['name'] = 'who';

        return $m;
    }));

    expect($result['breaks'])->toBe([[
        'rule' => 'parameter.renamed',
        'artifact' => 'api.core',
        'locator' => 'AzGuard\\AzGuard::check()$subject',
        'baseline' => 'subject',
        'current' => 'who',
    ]]);
});
