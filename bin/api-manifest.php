<?php

declare(strict_types=1);

/*
 * Machine manifest of the public API of each package (api-manifest.json).
 *
 *   php bin/api-manifest.php --write   regenerate both manifests
 *   php bin/api-manifest.php --check   exit 1 with a short diff when a manifest is stale
 *
 * Public by location (core): Kernel, Exceptions, Schema, Events, Testing, Storage\Models, Facades\AzGuard,
 * Concerns\HasAzGuard, Roles\BaseRole, Panels\{PanelBuilder,Panel,PanelProvider}. Contracts need an @api/@spi tag.
 * Any other class is public only with an explicit @api/@spi docblock tag.
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

const API_PACKAGES = ['packages/core', 'packages/filament'];

const API_LOCATION_PREFIXES = [
    'AzGuard\\Kernel\\',
    'AzGuard\\Exceptions\\',
    'AzGuard\\Schema\\',
    'AzGuard\\Events\\',
    'AzGuard\\Testing\\',
    'AzGuard\\Storage\\Models\\',
];

const API_LOCATION_CLASSES = [
    'AzGuard\\Facades\\AzGuard',
    'AzGuard\\Concerns\\HasAzGuard',
    'AzGuard\\Roles\\BaseRole',
    'AzGuard\\Panels\\PanelBuilder',
    'AzGuard\\Panels\\Panel',
    'AzGuard\\Panels\\PanelProvider',
];

/**
 * @return array{package: string, classes: list<array<string, mixed>>}
 */
function apiManifest(string $root, string $package): array
{
    /** @var array{name: string, autoload: array{psr-4: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents("{$root}/{$package}/composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $classes = [];

    foreach ($composer['autoload']['psr-4'] as $prefix => $dir) {
        $base = "{$root}/{$package}/".rtrim($dir, '/');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($base) + 1, -4);
            $fqcn = $prefix.str_replace('/', '\\', $relative);

            if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn) && ! enum_exists($fqcn)) {
                continue;
            }

            $entry = apiDescribe(new ReflectionClass($fqcn));

            if ($entry !== null) {
                $classes[$fqcn] = $entry;
            }
        }
    }

    ksort($classes, SORT_STRING);

    return ['package' => $composer['name'], 'classes' => array_values($classes)];
}

/**
 * @param  ReflectionClass<object>  $class
 * @return array<string, mixed>|null
 */
function apiDescribe(ReflectionClass $class): ?array
{
    $name = $class->getName();
    preg_match('/^\s*\*\s*@(api|spi)\b/m', (string) $class->getDocComment(), $tag);
    $byLocation = in_array($name, API_LOCATION_CLASSES, true)
        || array_filter(API_LOCATION_PREFIXES, static fn (string $p): bool => str_starts_with($name, $p)) !== [];

    if (str_starts_with($name, 'AzGuard\\Contracts\\') && $tag === []) {
        throw new RuntimeException("{$name}: a contract needs an @api or @spi tag.");
    }

    if (! $byLocation && $tag === []) {
        return null;
    }

    $entry = [
        'name' => $name,
        'kind' => match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            default => 'class',
        },
        'final' => $class->isFinal(),
        'abstract' => $class->isAbstract() && ! $class->isInterface(),
        'readonly' => $class->isReadOnly(),
        'parent' => $class->getParentClass() === false ? null : $class->getParentClass()->getName(),
        'interfaces' => apiSorted($class->getInterfaceNames()),
    ];

    if ($class->isEnum()) {
        $entry['cases'] = array_map(static fn (ReflectionEnumUnitCase $case): array => [
            'name' => $case->getName(),
            'value' => $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : null,
        ], (new ReflectionEnum($name))->getCases());
    }

    $entry['constants'] = apiSorted(array_values(array_map(
        static fn (ReflectionClassConstant $c): string => $c->getName(),
        array_filter(
            $class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC),
            static fn (ReflectionClassConstant $c): bool => ! $c->isEnumCase() && $c->getDeclaringClass()->getName() === $name,
        ),
    )));

    $properties = [];
    foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() === $name) {
            $properties[$property->getName()] = [
                'name' => $property->getName(),
                'type' => apiType($property->getType()),
                'readonly' => $property->isReadOnly(),
                'static' => $property->isStatic(),
            ];
        }
    }
    ksort($properties, SORT_STRING);
    $entry['properties'] = array_values($properties);

    $methods = [];
    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() === $name) {
            $methods[$method->getName()] = [
                'name' => $method->getName(),
                'static' => $method->isStatic(),
                'abstract' => $method->isAbstract() && ! $class->isInterface(),
                'parameters' => array_map(apiParameter(...), $method->getParameters()),
                'return' => apiType($method->getReturnType()),
            ];
        }
    }
    ksort($methods, SORT_STRING);
    $entry['methods'] = array_values($methods);

    $entry['stability'] = $tag[1] ?? 'api';
    $entry['via'] = $byLocation ? 'location' : 'tag';

    return $entry;
}

/**
 * @return array<string, mixed>
 */
function apiParameter(ReflectionParameter $parameter): array
{
    $described = [
        'name' => $parameter->getName(),
        'type' => apiType($parameter->getType()),
        'byRef' => $parameter->isPassedByReference(),
        'variadic' => $parameter->isVariadic(),
        'hasDefault' => $parameter->isDefaultValueAvailable(),
    ];

    if ($parameter->isDefaultValueAvailable()) {
        $default = $parameter->getDefaultValue();
        $described['default'] = is_object($default) && ! $default instanceof UnitEnum
            ? 'new '.$default::class
            : var_export($default, true);
    }

    return $described;
}

function apiType(?ReflectionType $type): ?string
{
    return $type === null ? null : (string) $type;
}

/**
 * @param  list<string>  $values
 * @return list<string>
 */
function apiSorted(array $values): array
{
    sort($values, SORT_STRING);

    return $values;
}

function apiEncode(mixed $manifest): string
{
    return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

/**
 * @param  array{classes: list<array<string, mixed>>}  $expected
 * @return list<string>
 */
function apiDiff(string $current, array $expected): array
{
    $old = [];
    foreach ((json_decode($current, true) ?: ['classes' => []])['classes'] ?? [] as $entry) {
        $old[$entry['name']] = $entry;
    }

    $new = [];
    foreach ($expected['classes'] as $entry) {
        $new[$entry['name']] = $entry;
    }

    $lines = [];
    foreach (array_diff_key($new, $old) as $name => $_) {
        $lines[] = "  + {$name}";
    }
    foreach (array_diff_key($old, $new) as $name => $_) {
        $lines[] = "  - {$name}";
    }
    foreach (array_intersect_key($new, $old) as $name => $entry) {
        if ($entry !== $old[$name]) {
            $lines[] = "  ~ {$name}";
        }
    }

    return $lines === [] ? ['  ~ formatting'] : $lines;
}

$mode = $argv[1] ?? '';

if (! in_array($mode, ['--write', '--check'], true)) {
    fwrite(STDERR, "usage: php bin/api-manifest.php --write|--check\n");
    exit(2);
}

$stale = false;

foreach (API_PACKAGES as $package) {
    $path = "{$root}/{$package}/api-manifest.json";
    $manifest = apiManifest($root, $package);
    $encoded = apiEncode($manifest);
    $current = is_file($path) ? (string) file_get_contents($path) : '';

    if ($current === $encoded) {
        continue;
    }

    if ($mode === '--write') {
        file_put_contents($path, $encoded);
        fwrite(STDOUT, "updated {$package}/api-manifest.json\n");

        continue;
    }

    $stale = true;
    fwrite(STDERR, "{$package}/api-manifest.json is stale (run composer api:manifest):\n".implode("\n", apiDiff($current, $manifest))."\n");
}

exit($stale ? 1 : 0);
