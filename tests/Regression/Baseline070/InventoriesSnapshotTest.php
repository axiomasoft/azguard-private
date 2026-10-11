<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Schema\Field;
use AzGuard\Tests\Arch\Ownership;
use AzGuard\Tests\Fixtures\Baseline070\Formats\FormatSnapshot;
use AzGuard\Tests\Fixtures\Guards\Admin\AdminGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Admin\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;
use Symfony\Component\Process\Process;

/*
 * I17, blocking now: the inventories of 0.7.0 that later phases are measured against — the API manifests, the types a
 * host may serialize, the reserved grant-field names and what folder discovery collects from Scopes/.
 */

uses(BootsWithCatalogCache::class);

const BASELINE_070 = '09612160c98ca6133c894a8f90f5653b9dac6b8f';

/*
 * The copies are byte copies of the manifests at the baseline commit. The sha256 is checked on every run; the copy is
 * also compared with `git show` when the commit is in the clone (a shallow CI checkout does not have it).
 */
it('holds byte copies of the 0.7.0 API manifests', function (string $package, string $sha256): void {
    $root = dirname(__DIR__, 3);
    $copy = (string) file_get_contents($root.'/tests/Fixtures/Baseline070/api-manifest/'.$package.'.json');
    $present = new Process(['git', 'cat-file', '-e', BASELINE_070.'^{commit}'], $root);
    $present->run();

    expect(hash('sha256', $copy))->toBe($sha256);

    if ($present->isSuccessful()) {
        $show = new Process(['git', 'show', BASELINE_070.':packages/'.$package.'/api-manifest.json'], $root);
        $show->mustRun();

        expect($show->getOutput())->toBe($copy);
    }
})->with([
    'core' => ['core', '0a6615db40a3efb28a1e58a508c256c64e03b70581f50dd9b61f032a41729882'],
    'filament' => ['filament', 'bb356dfe188f9844e6912e8963b66ebd53fbeb4cf325d32b1a5df399aa785348'],
]);

/**
 * The production types a host may serialize or store by class name: enums, concrete Kernel values, events,
 * exceptions and the classes that implement JsonSerializable or __serialize.
 *
 * @return array<class-string, list<string>> class => kinds, sorted by class
 */
function baselineSerializableTypes(): array
{
    $types = [];
    $root = dirname(__DIR__, 3).'/packages';

    foreach (['core/src' => 'AzGuard\\', 'filament/src' => 'AzGuard\\Filament\\'] as $directory => $namespace) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $class = $namespace.str_replace('/', '\\', substr($file->getPathname(), strlen($root.'/'.$directory) + 1, -4));

            if (! class_exists($class) && ! enum_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            $kinds = array_keys(array_filter([
                'enum' => $reflection->isEnum(),
                'kernel_value' => str_starts_with($class, 'AzGuard\\Kernel\\') && ! $reflection->isAbstract(),
                'event' => str_starts_with($class, 'AzGuard\\Events\\') && ! $reflection->isEnum(),
                'exception' => $reflection->isSubclassOf(Throwable::class),
                'json_serializable' => $reflection->implementsInterface(JsonSerializable::class),
                '__serialize' => $reflection->hasMethod('__serialize') && $reflection->getMethod('__serialize')->getDeclaringClass()->getName() !== Exception::class,
            ]));

            if ($kinds !== []) {
                $types[$class] = $kinds;
            }
        }
    }
    ksort($types, SORT_STRING);

    return $types;
}

it('lists the serializable types and pins the target of each in the type map', function (): void {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Baseline070/serializable-fqcn.json'), true, flags: JSON_THROW_ON_ERROR);
    $listed = [...$fixture['types'], ...$fixture['reported']];
    $kinds = array_combine(array_column($listed, 'fqcn'), array_column($listed, 'kinds'));
    ksort($kinds, SORT_STRING);

    expect($kinds)->toBe(baselineSerializableTypes());

    // The type map is the oracle: each listed type has its own row there, and the fixture repeats that row.
    $oracle = Ownership::fromOracle();

    foreach ($listed as $type) {
        $row = $oracle->typeRow($type['fqcn']);

        expect($row)->not->toBeNull($type['fqcn'].' has no type-map row')
            ->and($row['current'] ?? $row['target'])->toBe($type['fqcn'])
            ->and([$type['target'], $type['action'], $type['owner']])->toBe([$row['target'], $row['action'], $row['owner']], $type['fqcn']);
    }

    foreach ($fixture['types'] as $type) {
        expect($type['target'])->toBe($type['fqcn']);
    }

    // A reported type names the phase that moves or retires it, and that phase is the one of its type-map row.
    foreach ($fixture['reported'] as $type) {
        $phase = Ownership::removingPhase($oracle->typeRow($type['fqcn']));
        preg_match_all('/\bP\d+\b/', $type['note'], $named);

        expect($type['target'])->not->toBe($type['fqcn'])
            ->and($phase)->not->toBeNull($type['fqcn'])
            ->and(array_values(array_unique($named[0])))->toBe([$phase], $type['fqcn'].': '.$type['note']);
    }
});

/*
 * P2 reserves the grant-field prefix azg_. At 0.7.0 the rule of Field rejects only tenant_, subject_, context_ and
 * actor_, so an application could already declare an azg_ field; the upgrade map gives its rename path.
 */
it('accepts grant fields with the azg_ prefix at 0.7.0 and rejects the reserved scope prefixes', function (): void {
    $accepted = [];

    foreach (['azg_region', 'azg_', 'region', 'tenant_region', 'subject_region', 'context_region', 'actor_region'] as $name) {
        try {
            Field::string($name);
            $accepted[$name] = true;
        } catch (DefinitionException) {
            $accepted[$name] = false;
        }
    }
    [$actual, $stored] = FormatSnapshot::compare('grant-field-names', $accepted);

    expect($accepted['azg_region'])->toBeTrue()->and($actual)->toBe($stored);
});

describe('Scopes/ folder discovery', function (): void {
    beforeEach(function (): void {
        FixturePanel::reset();
        self::$catalogCachePath = sys_get_temp_dir().'/azguard-baseline-'.bin2hex(random_bytes(6)).'/azguard.php';
        self::$catalogBuildId = 'baseline-build';
    });
    afterEach(function (): void {
        @unlink(self::$catalogCachePath);
        @rmdir(dirname(self::$catalogCachePath));
    });

    it('collects the scope definitions of the Scopes folder into the discovery and the catalog cache', function (): void {
        $this->bootPanels(['providers' => [AdminGuardPanelProvider::class]]);
        $registry = app(PanelRegistry::class);
        $this->artisan('azguard:catalog:cache')->assertSuccessful();
        $cached = (require self::$catalogCachePath)['panels']['admin']['discovery'];

        $discovery = $registry->discovery('admin');

        [$actual, $stored] = FormatSnapshot::compare('scopes-discovery', [
            'folder' => $discovery['names']['scopes'],
            'discovered' => $discovery['scopes'],
            'cached' => $cached['scopes'],
            'definition_types' => array_values(array_map(static fn ($definition): string => $definition->type(), $registry->get('admin')->scopeDefinitions())),
        ]);

        expect($discovery['scopes'])->toBe([ProjectScope::class])->and($actual)->toBe($stored);
    });
});
