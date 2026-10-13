<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Schema\Field;
use AzGuard\Tests\Arch\Ownership;
use AzGuard\Tests\Fixtures\Formats\FormatSnapshot;
use AzGuard\Tests\Fixtures\Guards\Admin\AdminGuardPanelProvider;
use AzGuard\Tests\Fixtures\Guards\Admin\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Panels\FixturePanel;
use AzGuard\Tests\Fixtures\Sources\BootsWithCatalogCache;

/*
 * I17, blocking now: the inventories of the current formats — the types a host may serialize, the reserved grant-field
 * names and what folder discovery collects from Scopes/.
 */

uses(BootsWithCatalogCache::class);

/**
 * The production types a host may serialize or store by class name: enums, concrete Kernel values, events,
 * exceptions and the classes that implement JsonSerializable or __serialize.
 *
 * @return array<class-string, list<string>> class => kinds, sorted by class
 */
function serializableTypes(): array
{
    $types = [];
    $root = dirname(__DIR__, 3).'/packages';

    foreach (FormatSnapshot::productionRoots($root) as $directory => $namespace) {
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

it('lists the serializable types and pins them', function (): void {
    $types = serializableTypes();
    [$actual, $stored] = FormatSnapshot::compare('serializable-types', ['types' => array_map(
        static fn (string $fqcn, array $kinds): array => ['fqcn' => $fqcn, 'kinds' => $kinds],
        array_keys($types),
        array_values($types),
    )]);

    expect($actual)->toBe($stored);

    // Each serializable type is a production type of the type map: the oracle owns its FQCN.
    $oracle = Ownership::fromOracle();

    foreach (array_keys($types) as $fqcn) {
        expect($oracle->typeRow($fqcn))->not->toBeNull($fqcn.' has no type-map row');
    }
});

// The grant-field prefix azg_ is reserved next to tenant_, subject_, context_ and actor_.
it('rejects grant fields with the reserved azg_ prefix and the reserved scope prefixes', function (): void {
    $accepted = [];

    foreach (['azg_region', 'azg_', 'region', 'tenant_region', 'subject_region', 'context_region', 'actor_region'] as $name) {
        try {
            Field::string($name);
            $accepted[$name] = true;
        } catch (DefinitionException) {
            $accepted[$name] = false;
        }
    }

    expect($accepted)->toBe([
        'azg_region' => false, 'azg_' => false, 'region' => true,
        'tenant_region' => false, 'subject_region' => false, 'context_region' => false, 'actor_region' => false,
    ]);
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

        // A discovered definition does not become a panel scope definition: the panel declares none.
        expect($discovery['names']['scopes'])->toBe('Scopes')
            ->and($discovery['scopes'])->toBe([ProjectScope::class])
            ->and($cached['scopes'])->toBe([ProjectScope::class])
            ->and(array_values(array_map(static fn ($definition): string => $definition->type(), $registry->get('admin')->scopeDefinitions())))->toBe([]);
    });
});
