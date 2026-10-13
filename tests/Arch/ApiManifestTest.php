<?php

declare(strict_types=1);

use AzGuard\Attributes\Api;
use AzGuard\Attributes\Spi;
use AzGuard\Testing\AzGuardFake;
use AzGuard\Tests\Arch\ApiUsage;
use AzGuard\Tests\Arch\BoundaryScan;
use AzGuard\Tests\Arch\Ownership;
use AzGuard\Tests\Arch\SourceScan;

/*
 * The supported surface (D9): publication is the #[Api]/#[Spi] attribute, the attributed types equal the markers of the
 * type map (tests/Arch/Fixtures/target-map.json), and the generated api-manifest.json files are current.
 *
 * I4 (report mode until P3 exit): files the oracle gives to a module or to Filament, and the stubs of core, reference
 * only supported core types and the members their manifests list. The current violations must equal
 * tests/Arch/Allowlists/i4.json exactly; entries with owner P2 are the work list of P2.2–P2.13.
 */

function apiManifestRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * The publication marker of every production type, as the type map writes it: `Api`, `Spi(Implement)`, `Spi(Call)`.
 *
 * @return array<string, string> marker by FQCN, sorted
 */
function apiAttributedTypes(): array
{
    $types = [];

    foreach (SourceScan::files() as $file) {
        $fqcn = Ownership::declaredType((string) file_get_contents($file));

        if ($fqcn === null || ! (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn))) {
            continue;
        }
        $class = new ReflectionClass($fqcn);
        $spi = $class->getAttributes(Spi::class);

        if ($class->getAttributes(Api::class) !== []) {
            $types[$fqcn] = 'Api';
        } elseif ($spi !== []) {
            $types[$fqcn] = 'Spi('.ucfirst($spi[0]->newInstance()->kind->value).')';
        }
    }
    ksort($types, SORT_STRING);

    return $types;
}

/**
 * Attributed types under an `\Internal\` namespace, where no supported type may live.
 *
 * @param  array<string, string>  $attributed
 * @return list<string>
 */
function apiInternalAttributed(array $attributed): array
{
    return array_values(array_filter(array_keys($attributed), static fn (string $fqcn): bool => str_contains($fqcn, '\\Internal\\')));
}

/**
 * The differences between the attributed types and the oracle. An attributed type must have the marker of its row; an
 * Api/Spi row must be attributed under the FQCN it has during P2: its target when that exists, or its current FQCN when
 * the row changes the FQCN only in P3. A row that P2 renames or moves is marked by the item that does it.
 *
 * @param  array<string, string>  $attributed  marker by FQCN
 * @param  callable(string): bool  $exists
 * @return list<string>
 */
function apiOracleDifference(Ownership $oracle, array $attributed, callable $exists): array
{
    $differences = [];

    foreach ($attributed as $fqcn => $marker) {
        $row = $oracle->typeRow($fqcn);

        if ($row === null) {
            $differences[] = $fqcn.': attributed '.$marker.', but the type map has no row';
        } elseif (($row['marker'] ?? null) !== $marker) {
            $differences[] = $fqcn.': attributed '.$marker.', the type map says '.($row['marker'] ?? 'nothing');
        }
    }

    foreach ($oracle->rows() as $row) {
        if (! in_array($row['marker'] ?? null, ['Api', 'Spi(Implement)', 'Spi(Call)'], true)) {
            continue;
        }
        $target = $row['target'] ?? null;
        $current = $row['current'] ?? null;
        $fqcn = match (true) {
            is_string($target) && ! str_contains($target, ' + ') && $exists($target) => $target,
            is_string($current) && ($row['phase'] ?? null) === 'P3' && $exists($current) => $current,
            default => null,
        };

        if ($fqcn !== null && ! isset($attributed[$fqcn])) {
            $differences[] = $fqcn.': the type map marks it '.$row['marker'].', but it carries no attribute';
        }
    }
    sort($differences);

    return $differences;
}

/**
 * The attributed enums whose `@closed`/`@open` tags are not exactly the one of the type-map enum column (D15).
 *
 * @param  array<string, list<string>>  $tags  the tags of each attributed enum, by FQCN
 * @return list<string>
 */
function apiEnumTagDifference(Ownership $oracle, array $tags): array
{
    $differences = [];

    foreach ($tags as $fqcn => $found) {
        $expected = $oracle->typeRow($fqcn)['enum'] ?? null;

        if ($found !== [$expected]) {
            $differences[] = $fqcn.': tags ['.implode(', ', $found).'], the type map says '.($expected ?? 'nothing');
        }
    }

    return $differences;
}

/** @param array<string, mixed> $overrides */
function apiOracleRow(string $fqcn, string $marker, array $overrides = []): array
{
    return ['current' => $fqcn, 'current_path' => null, 'target' => $fqcn, 'target_path' => null, 'owner' => 'core', 'marker' => $marker,
        'enum' => null, 'action' => 'keep', 'phase' => '—', ...$overrides];
}

it('keeps both api manifests current', function (): void {
    $process = proc_open(
        [PHP_BINARY, apiManifestRoot().'/bin/api-manifest.php', '--check'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        apiManifestRoot(),
    );

    expect($process)->toBeResource();

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $output);
});

it('records the publication, since and enum class of every supported type and its override points', function (): void {
    foreach (ApiUsage::supported() as $entry) {
        $class = new ReflectionClass($entry['name']);

        expect($entry['publication'])->toBeIn(['api', 'spi-implement', 'spi-call'], $entry['name'])
            ->and($entry['since'])->toMatch('/\A\d+\.\d+\z/', $entry['name'])
            ->and(array_key_exists('enum', $entry))->toBe($class->isEnum(), $entry['name']);

        foreach ([...$entry['methods'], ...$entry['properties'], ...$entry['constants']] as $member) {
            expect($member['visibility'] === 'public' || ! $class->isFinal())->toBeTrue($entry['name'].'::'.$member['name'].' is protected in a final class');
        }
    }
});

it('excludes internal classes from publication by their docblock', function (): void {
    $internal = [];

    foreach (ApiUsage::supported() as $entry) {
        if (preg_match('/^\s*\*\s*@internal\b/m', (string) (new ReflectionClass($entry['name']))->getDocComment()) === 1) {
            $internal[] = $entry['name'];
        }
    }

    expect($internal)->toBe([]);
});

it('publishes no type under an Internal namespace', function (): void {
    expect(apiInternalAttributed(apiAttributedTypes()))->toBe([]);
});

it('finds an attributed type under an Internal namespace', function (): void {
    expect(apiInternalAttributed(['AzGuard\Panels\Panel' => 'Api', 'AzGuard\Panels\Internal\Probe' => 'Api']))->toBe(['AzGuard\Panels\Internal\Probe']);
});

it('marks exactly the types the type map publishes, with its marker', function (): void {
    $exists = static fn (string $fqcn): bool => class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);

    expect(apiOracleDifference(Ownership::fromOracle(), apiAttributedTypes(), $exists))->toBe([]);
});

it('reports a wrong marker, a marker without a row and a missing attribute against the oracle', function (): void {
    $oracle = Ownership::of([
        apiOracleRow('AzGuard\Panels\Panel', 'Api'),
        apiOracleRow('AzGuard\Panels\Reads', 'Api'),
        apiOracleRow('AzGuard\Contracts\Plugins\Plugin', 'Spi(Implement)'),
        apiOracleRow('AzGuard\Old\Moved', 'Api', ['target' => 'AzGuard\New\Moved', 'action' => 'move', 'phase' => 'P2']),
        apiOracleRow('AzGuard\Old\Later', 'Api', ['target' => 'AzGuard\Module\Later', 'action' => 'move', 'phase' => 'P3']),
        apiOracleRow('AzGuard\Panels\Hidden', 'internal'),
    ]);
    $exists = static fn (string $fqcn): bool => in_array($fqcn, ['AzGuard\Panels\Panel', 'AzGuard\Panels\Reads', 'AzGuard\Contracts\Plugins\Plugin',
        'AzGuard\Old\Moved', 'AzGuard\Old\Later', 'AzGuard\Panels\Hidden'], true);

    expect(apiOracleDifference($oracle, [
        'AzGuard\Panels\Panel' => 'Api',
        'AzGuard\Contracts\Plugins\Plugin' => 'Spi(Call)',
        'AzGuard\Panels\Hidden' => 'Api',
        'AzGuard\Panels\Unknown' => 'Api',
    ], $exists))->toBe([
        'AzGuard\Contracts\Plugins\Plugin: attributed Spi(Call), the type map says Spi(Implement)',
        'AzGuard\Old\Later: the type map marks it Api, but it carries no attribute',
        'AzGuard\Panels\Hidden: attributed Api, the type map says internal',
        'AzGuard\Panels\Reads: the type map marks it Api, but it carries no attribute',
        'AzGuard\Panels\Unknown: attributed Api, but the type map has no row',
    ]);
});

it('gives every attributed enum exactly the closed or open tag of the type map', function (): void {
    $tags = [];

    foreach (array_keys(apiAttributedTypes()) as $fqcn) {
        if (enum_exists($fqcn)) {
            preg_match_all('/^\s*\*\s*@(closed|open)\b/m', (string) (new ReflectionEnum($fqcn))->getDocComment(), $found);
            $tags[$fqcn] = $found[1];
        }
    }

    expect($tags)->not->toBe([])
        ->and(apiEnumTagDifference(Ownership::fromOracle(), $tags))->toBe([]);
});

it('reports a missing, a second and a wrong enum tag', function (): void {
    $oracle = Ownership::of([
        apiOracleRow('AzGuard\Panels\Reads', 'Api', ['enum' => 'closed']),
        apiOracleRow('AzGuard\Events\EventType', 'Api', ['enum' => 'open']),
        apiOracleRow('AzGuard\Panels\StateRefresh', 'Api', ['enum' => 'closed']),
        apiOracleRow('AzGuard\Changes\ChangeType', 'Api', ['enum' => 'open']),
    ]);

    expect(apiEnumTagDifference($oracle, [
        'AzGuard\Panels\Reads' => [],
        'AzGuard\Events\EventType' => ['open', 'closed'],
        'AzGuard\Panels\StateRefresh' => ['open'],
        'AzGuard\Changes\ChangeType' => ['open'],
    ]))->toBe([
        'AzGuard\Panels\Reads: tags [], the type map says closed',
        'AzGuard\Events\EventType: tags [open, closed], the type map says open',
        'AzGuard\Panels\StateRefresh: tags [open], the type map says closed',
    ]);
});

it('keeps the I4 allowlist empty once the modules and their stubs use the supported surface only (P3.7)', function (): void {
    expect(BoundaryScan::allowlist('i4'))->toBe([]);
});

it('I4 (report mode, blocking after P3): modules, Filament and stubs use only supported core types and members', function (): void {
    $ownership = Ownership::fromOracle();
    $found = ApiUsage::i4Violations($ownership, ApiUsage::i4Files($ownership), ApiUsage::supported());
    $difference = BoundaryScan::compare($found, BoundaryScan::allowlist('i4'));

    expect(ApiUsage::i4Files($ownership))->toContain(apiManifestRoot().'/packages/core/modules/devtools/stubs/policy.stub', apiManifestRoot().'/packages/core/modules/audit/stubs/audit-migration.stub')
        ->and($difference['new'])->toBe([], 'new I4 violations: use a supported type or member, add no allowlist entry')
        ->and($difference['stale'])->toBe([], 'stale I4 allowlist entries: delete them from tests/Arch/Allowlists/i4.json');
});

it('I4: reports an unsupported core type and a member the manifest does not list, and nothing for a listed one', function (): void {
    $root = sys_get_temp_dir().'/azguard-i4-'.bin2hex(random_bytes(4));
    $file = $root.'/packages/core/src/Scopes/Probe.php';
    mkdir(dirname($file), 0777, true);
    file_put_contents($file, <<<'PHP'
        <?php
        namespace AzGuard\Scopes;
        use AzGuard\Changes\Internal\ChangePipeline;
        use AzGuard\Kernel\Identity\TenantRef;
        use AzGuard\Kernel\Identity\SubjectRef;
        final class Probe {
            public function __construct(private SubjectRef $subject) {}
            public function run(TenantRef $tenant, mixed $unknown): string {
                $made = TenantRef::of('team', 1);
                $unknown->key();
                return $tenant->key().$made->type().$this->subject->key().ChangePipeline::class;
            }
        }
        PHP);
    $entry = static fn (string $name, array $methods): array => ['name' => $name, 'publication' => 'api', 'constants' => [], 'properties' => [],
        'methods' => array_map(static fn (string $m): array => ['name' => $m], $methods)];
    $oracle = Ownership::of([
        apiOracleRow('AzGuard\Scopes\Probe', 'Api', ['current_path' => 'packages/core/src/Scopes/Probe.php', 'owner' => 'scopes', 'action' => 'relocate', 'phase' => 'P3']),
        apiOracleRow('AzGuard\Changes\Internal\ChangePipeline', 'internal', ['action' => 'move', 'phase' => 'P3']),
        apiOracleRow('AzGuard\Kernel\Identity\TenantRef', 'Api'),
        apiOracleRow('AzGuard\Kernel\Identity\SubjectRef', 'Api'),
    ]);
    $supported = [
        'azguard\kernel\identity\tenantref' => $entry('AzGuard\Kernel\Identity\TenantRef', ['key', 'type']),
        'azguard\kernel\identity\subjectref' => $entry('AzGuard\Kernel\Identity\SubjectRef', []),
    ];

    try {
        $violations = ApiUsage::i4Violations($oracle, [$file], $supported, $root);
    } finally {
        unlink($file);
        rmdir(dirname($file));
        rmdir(dirname($file, 2));
        rmdir(dirname($file, 3));
        rmdir(dirname($file, 4));
        rmdir($root);
    }

    expect(array_map(static fn (array $v): string => implode(' ', [$v['invariant'], $v['file'], $v['kind'], $v['reference'], $v['owner_phase']]), $violations))->toBe([
        'I4 packages/core/src/Scopes/Probe.php type AzGuard\Changes\Internal\ChangePipeline P3',
        'I4 packages/core/src/Scopes/Probe.php member AzGuard\Kernel\Identity\SubjectRef::key P3',
        'I4 packages/core/src/Scopes/Probe.php member AzGuard\Kernel\Identity\TenantRef::of P3',
    ]);
});

it('I4: types nullable unions, closures, arrow functions and $this, and leaves expressions untyped', function (): void {
    $code = <<<'PHP'
        <?php
        namespace AzGuard\Scopes;
        use AzGuard\Panels\Panel;
        use AzGuard\Kernel\Identity\TenantRef;
        use AzGuard\Kernel\Identity\SubjectRef;
        final class Probe extends Base {
            public function __construct(private TenantRef|null $tenant) {}
            public function run(?Panel $panel, null|TenantRef $other, SubjectRef $subject, $flag): void {
                $other->key();
                $this->tenant->type();
                $inside = function () use ($subject) { $subject->key(); };
                $subject->type();
                $arrow = fn (Panel $subject) => $subject->id();
                $subject->id();
                $guess = FLAG ? $flag : null;
                $flag->ignored();
                $this->inherited();
            }
        }
        PHP;

    expect(array_map(static fn (array $reference): string => implode(' ', $reference), ApiUsage::memberReferences($code)))->toBe([
        'AzGuard\Kernel\Identity\TenantRef key method',
        'AzGuard\Scopes\Probe tenant property',
        'AzGuard\Kernel\Identity\TenantRef type method',
        'AzGuard\Kernel\Identity\SubjectRef key method',
        'AzGuard\Kernel\Identity\SubjectRef type method',
        'AzGuard\Panels\Panel id method',
        'AzGuard\Kernel\Identity\SubjectRef id method',
        'AzGuard\Scopes\Probe inherited method',
    ]);
});

/**
 * The supported public methods of a class with their signatures: parameter names, types, defaults and the return type.
 *
 * @return array<string, string>
 */
function apiSignatures(string $class): array
{
    $signatures = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || preg_match('/@internal\b/', (string) $method->getDocComment()) === 1) {
            continue;
        }
        $parameters = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getType().' $'.$parameter->getName()
            .($parameter->isDefaultValueAvailable() ? ' = '.var_export($parameter->getDefaultValue(), true) : ''), $method->getParameters());
        $signatures[$method->getName()] = '('.implode(', ', $parameters).'): '.$method->getReturnType();
    }
    ksort($signatures);

    return $signatures;
}

it('generates the facade methods from the entry class and records them in the manifest', function (): void {
    preg_match_all('/@method static .+? (\w+)\(/', (string) (new ReflectionClass(AzGuard\Facades\AzGuard::class))->getDocComment(), $declared);
    $entry = array_keys(apiSignatures(AzGuard\AzGuard::class));
    $manifest = json_decode((string) file_get_contents(apiManifestRoot().'/packages/core/api-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    sort($declared[1]);

    expect($entry)->toContain('panel', 'check', 'storage', 'storages', 'fake')
        ->and($declared[1])->toBe($entry)
        ->and(array_column($manifest['facade'], 'name'))->toBe($entry);
});

it('mirrors every public method of the entry class on the consumer fake with the same signature', function (): void {
    $entry = apiSignatures(AzGuard\AzGuard::class);
    $fake = apiSignatures(AzGuardFake::class);
    // The fake declares `fake(): self`; PHP 8.5 reflects `self` as the class name, earlier versions as `self`.
    $fake['fake'] = str_replace(AzGuardFake::class, 'self', $fake['fake']);

    expect(array_intersect_key($fake, $entry))->toBe(array_merge($entry, ['fake' => '(): self']))
        ->and($entry['fake'])->toBe('(): AzGuard\Testing\AzGuardFake');
});
