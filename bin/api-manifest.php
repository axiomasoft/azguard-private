<?php

declare(strict_types=1);

/*
 * Machine manifest of the supported surface of each package (api-manifest.json).
 *
 *   php bin/api-manifest.php --write   regenerate both manifests
 *   php bin/api-manifest.php --check   exit 1 with a short diff when a manifest is stale
 *
 * Publication is the attribute only (D9): a type with #[Api] or #[Spi] is supported, any other type is internal wherever
 * it lives, and a type under `\Internal\` with an attribute is an error. Per supported type the manifest records the
 * attribute kind and `since`, the public members and, for a class that is not final, the protected members (override
 * points) with full signatures and parameter names, constants with values, enum cases with the closed/open class, the
 * PHPDoc generic and class-string contracts of the members and the `@no-named-arguments` stance. A member documented
 * `@internal` is excluded. The core manifest also lists the facade methods derived from the entry class
 * `AzGuard\AzGuard` and every manifest names the PSR-4 roots of its package.
 */

use AzGuard\Attributes\Api;
use AzGuard\Attributes\Spi;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

const API_PACKAGES = ['packages/core', 'packages/filament'];

/** The entry class whose public methods the facade forwards. */
const API_ENTRY = 'AzGuard\\AzGuard';

/** The facade generated from the entry class: its `@method static` block is never edited by hand. */
const API_FACADE = 'packages/core/src/Facades/AzGuard.php';

/*
 * Classes declared differently by the installed Laravel. The manifest records the Laravel 13 shape (the dev
 * requirement); on an older Laravel the committed entry is kept instead of the fallback declaration.
 */
const API_LARAVEL_DEPENDENT = [
    'AzGuard\\Attributes\\CheckPermission' => 'Illuminate\\Routing\\Attributes\\Controllers\\Middleware',
];

/**
 * @return array{package: string, roots: array<string, string>, facade?: list<array<string, mixed>>, classes: list<array<string, mixed>>}
 */
function apiManifest(string $root, string $package): array
{
    $committed = [];
    $path = "{$root}/{$package}/api-manifest.json";
    $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    foreach (is_array($stored) ? $stored['classes'] ?? [] : [] as $entry) {
        $committed[$entry['name']] = $entry;
    }

    /** @var array{name: string, autoload: array{psr-4: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents("{$root}/{$package}/composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $classes = [];
    $hasEntry = false;

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

            $hasEntry = $hasEntry || $fqcn === API_ENTRY;
            $dependsOn = API_LARAVEL_DEPENDENT[$fqcn] ?? null;
            $entry = $dependsOn !== null && ! class_exists($dependsOn) && isset($committed[$fqcn])
                ? $committed[$fqcn]
                : apiDescribe(new ReflectionClass($fqcn));

            if ($entry !== null) {
                $classes[$fqcn] = $entry;
            }
        }
    }

    ksort($classes, SORT_STRING);
    $roots = $composer['autoload']['psr-4'];
    ksort($roots, SORT_STRING);
    $manifest = ['package' => $composer['name'], 'roots' => $roots];

    if ($package === 'packages/core') {
        $manifest['facade'] = $hasEntry ? apiMembers(new ReflectionClass(API_ENTRY), facade: true)['methods'] : [];
    }

    return [...$manifest, 'classes' => array_values($classes)];
}

/**
 * The publication of a type: `api`, `spi-implement` or `spi-call` with its `since`, or null for an internal type.
 *
 * @param  ReflectionClass<object>  $class
 * @return array{publication: string, since: string}|null
 */
function apiPublication(ReflectionClass $class): ?array
{
    $api = $class->getAttributes(Api::class);
    $spi = $class->getAttributes(Spi::class);

    if ($api === [] && $spi === []) {
        return null;
    }

    $name = $class->getName();

    if (str_contains($name, '\\Internal\\')) {
        throw new RuntimeException("{$name}: a type under \\Internal\\ carries no publication attribute.");
    }

    if ($api !== [] && $spi !== []) {
        throw new RuntimeException("{$name}: a type carries either #[Api] or #[Spi], not both.");
    }

    if ($api !== []) {
        return ['publication' => 'api', 'since' => $api[0]->newInstance()->since];
    }

    $marker = $spi[0]->newInstance();

    return ['publication' => 'spi-'.$marker->kind->value, 'since' => $marker->since];
}

/**
 * @param  ReflectionClass<object>  $class
 * @return array<string, mixed>|null
 */
function apiDescribe(ReflectionClass $class): ?array
{
    $publication = apiPublication($class);

    if ($publication === null) {
        return null;
    }

    $name = $class->getName();
    $entry = [
        'name' => $name,
        ...$publication,
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
        'namedArguments' => ! apiHasTag($class->getDocComment(), 'no-named-arguments'),
        'contracts' => apiDocContracts($class->getDocComment()),
    ];

    if ($class->isEnum()) {
        preg_match_all('/^\s*\*\s*@(closed|open)\b/m', (string) $class->getDocComment(), $tags);

        if (count($tags[1]) !== 1) {
            throw new RuntimeException("{$name}: a supported enum carries exactly one @closed or @open tag.");
        }

        $entry['enum'] = $tags[1][0];
        $entry['cases'] = array_map(static fn (ReflectionEnumUnitCase $case): array => [
            'name' => $case->getName(),
            'value' => $case instanceof ReflectionEnumBackedCase ? $case->getBackingValue() : null,
        ], (new ReflectionEnum($name))->getCases());
    }

    return [...$entry, ...apiMembers($class)];
}

/**
 * The supported members declared by a class: public ones and, when the class is not final, protected ones (override
 * points). A member documented `@internal` is not supported. With `$facade` only the public methods, as the facade
 * forwards them.
 *
 * @param  ReflectionClass<object>  $class
 * @return array{constants: list<array<string, mixed>>, properties: list<array<string, mixed>>, methods: list<array<string, mixed>>}
 */
function apiMembers(ReflectionClass $class, bool $facade = false): array
{
    $name = $class->getName();
    $visibility = $class->isFinal() || $facade ? ReflectionMethod::IS_PUBLIC : ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED;
    $classNamed = ! apiHasTag($class->getDocComment(), 'no-named-arguments');

    $constants = [];
    foreach ($class->getReflectionConstants($visibility) as $constant) {
        if ($constant->isEnumCase() || $constant->getDeclaringClass()->getName() !== $name || apiHasTag($constant->getDocComment(), 'internal')) {
            continue;
        }
        $constants[$constant->getName()] = [
            'name' => $constant->getName(),
            'visibility' => apiVisibility($constant),
            'final' => $constant->isFinal(),
            'value' => apiValue($constant->getValue()),
        ];
    }
    ksort($constants, SORT_STRING);

    $properties = [];
    foreach ($class->getProperties($visibility) as $property) {
        if ($property->getDeclaringClass()->getName() !== $name || apiHasTag($property->getDocComment(), 'internal')) {
            continue;
        }
        $properties[$property->getName()] = [
            'name' => $property->getName(),
            'visibility' => apiVisibility($property),
            'type' => apiType($property->getType(), $name),
            'readonly' => $property->isReadOnly(),
            'static' => $property->isStatic(),
            'contracts' => apiDocContracts($property->getDocComment()),
        ];
    }
    ksort($properties, SORT_STRING);

    $methods = [];
    foreach ($class->getMethods($visibility) as $method) {
        if ($method->getDeclaringClass()->getName() !== $name || apiHasTag($method->getDocComment(), 'internal')) {
            continue;
        }
        $methods[$method->getName()] = [
            'name' => $method->getName(),
            'visibility' => apiVisibility($method),
            'static' => $method->isStatic(),
            'abstract' => $method->isAbstract() && ! $class->isInterface(),
            'final' => $method->isFinal(),
            'parameters' => array_map(static fn (ReflectionParameter $parameter): array => apiParameter($parameter, $name), $method->getParameters()),
            'return' => apiType($method->getReturnType(), $name),
            'returnsReference' => $method->returnsReference(),
            'namedArguments' => $classNamed && ! apiHasTag($method->getDocComment(), 'no-named-arguments'),
            'contracts' => apiDocContracts($method->getDocComment()),
        ];
    }
    ksort($methods, SORT_STRING);

    return ['constants' => array_values($constants), 'properties' => array_values($properties), 'methods' => array_values($methods)];
}

function apiVisibility(ReflectionClassConstant|ReflectionProperty|ReflectionMethod $member): string
{
    return $member->isPublic() ? 'public' : 'protected';
}

function apiHasTag(string|false $doc, string $tag): bool
{
    return preg_match('/^\s*(?:\/\*\*|\*)\s*@'.preg_quote($tag, '/').'\b/m', (string) $doc) === 1;
}

/**
 * The PHPDoc type contracts of a docblock: the `@param`, `@return`, `@var` and `@template` lines whose type is a
 * generic, a shape or a class-string, and every `@phpstan-type` / `@phpstan-import-type` alias line, with whitespace
 * normalised. They are part of the supported contract.
 *
 * @return list<string>
 */
function apiDocContracts(string|false $doc): array
{
    $contracts = [];

    foreach (preg_split('/\R/', (string) $doc) ?: [] as $line) {
        $line = trim((string) preg_replace('/^\s*(?:\/\*\*|\*\/|\*)?\s*|\s*\*\/\s*$/', '', $line));

        if (preg_match('/^@(?:phpstan-|psalm-)?(param|return|var|template(?:-covariant|-contravariant)?|type|import-type)\b/', $line) !== 1) {
            continue;
        }

        // A type alias is part of every signature that names it: record its definition and its import as they stand.
        if (str_starts_with($line, '@template') || preg_match('/^@(?:phpstan-|psalm-)(?:import-)?type\b/', $line) === 1 || preg_match('/[<{]|class-string/', $line) === 1) {
            $contracts[] = (string) preg_replace('/\s+/', ' ', $line);
        }
    }

    return $contracts;
}

function apiValue(mixed $value): mixed
{
    return match (true) {
        $value instanceof UnitEnum => $value::class.'::'.$value->name,
        is_array($value) => array_map(apiValue(...), $value),
        is_object($value) => 'new '.$value::class,
        default => $value,
    };
}

/**
 * @return array<string, mixed>
 */
function apiParameter(ReflectionParameter $parameter, string $self): array
{
    $described = [
        'name' => $parameter->getName(),
        'type' => apiType($parameter->getType(), $self),
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

/**
 * The declared type with the class itself written as `self`. PHP 8.5 reflection resolves `self` to the class name
 * while 8.3/8.4 keep `self`; one spelling keeps the manifest identical on every supported PHP.
 */
function apiType(?ReflectionType $type, string $self): ?string
{
    if ($type === null) {
        return null;
    }

    return preg_replace_callback('/[A-Za-z_\\\\][A-Za-z0-9_\\\\]*/', static fn (array $m): string => ltrim($m[0], '\\') === $self ? 'self' : $m[0], (string) $type);
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
    $decoded = json_decode($current, true) ?: [];
    $old = [];
    foreach ($decoded['classes'] ?? [] as $entry) {
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
    foreach (['roots', 'facade'] as $key) {
        if (($decoded[$key] ?? null) !== ($expected[$key] ?? null)) {
            $lines[] = "  ~ {$key}";
        }
    }

    return $lines === [] ? ['  ~ formatting'] : $lines;
}

/**
 * The source of the facade: one `@method static` line per supported public method of the entry class, with the
 * parameter and return types of its PHPDoc where it has them (template types become `mixed`) and the declared types
 * otherwise. The imports are the classes those lines name.
 */
function apiFacadeSource(): string
{
    $entry = new ReflectionClass(API_ENTRY);
    $entryImports = apiImports((string) file_get_contents((string) $entry->getFileName()));
    $imports = ['AzGuard\Attributes\Api' => true, 'Illuminate\Support\Facades\Facade' => true];
    $short = static function (string $fqcn) use (&$imports): string {
        // The facade zone imports no testing code (ZonesArchTest): the fake is named by its full name.
        if (str_starts_with($fqcn, 'AzGuard\\Testing\\')) {
            return '\\'.$fqcn;
        }
        $imports[$fqcn] = true;

        return substr($fqcn, (int) strrpos('\\'.$fqcn, '\\'));
    };
    $lines = [];

    foreach (apiMembers($entry, facade: true)['methods'] as $described) {
        $method = $entry->getMethod($described['name']);
        $doc = (string) $method->getDocComment();
        preg_match_all('/@template\s+(\w+)/', $doc, $templates);
        $documentedType = static function (string $type) use ($templates, $entryImports, $short): string {
            foreach ($templates[1] as $template) {
                $type = (string) preg_replace('/\b'.preg_quote($template, '/').'\b/', 'mixed', $type);
            }

            return (string) preg_replace_callback('/(?<![\w\\\\$-])[A-Z]\w*/', static function (array $name) use ($entryImports, $short): string {
                $fqcn = $entryImports[strtolower($name[0])] ?? null;

                return $fqcn === null ? $name[0] : $short($fqcn);
            }, $type);
        };
        $declaredType = static function (?ReflectionType $type) use ($short): string {
            if ($type === null) {
                return 'mixed';
            }

            return (string) preg_replace_callback('/[A-Za-z_]\w*(?:\\\\\w+)*/', static function (array $name) use ($short): string {
                return class_exists($name[0]) || interface_exists($name[0]) || enum_exists($name[0]) ? $short($name[0]) : $name[0];
            }, (string) $type);
        };
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = preg_match('/@param\s+(.+?)\s+\$'.$parameter->getName().'\b/', $doc, $documented) === 1
                ? $documentedType(trim($documented[1]))
                : $declaredType($parameter->getType());
            $default = $parameter->isDefaultValueAvailable() ? ' = '.apiDefault($parameter->getDefaultValue()) : '';
            $parameters[] = $type.' $'.$parameter->getName().$default;
        }
        $return = ($documented = apiDocReturn($doc)) !== null ? $documentedType($documented) : $declaredType($method->getReturnType());
        $lines[] = ' * @method static '.$return.' '.$method->getName().'('.implode(', ', $parameters).')';
    }
    $names = array_keys($imports);
    sort($names, SORT_STRING | SORT_FLAG_CASE);
    $uses = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $names));
    $methods = implode("\n", $lines);

    return <<<PHP
        <?php

        declare(strict_types=1);

        namespace AzGuard\Facades;

        {$uses}

        /**
         * Entry point of the package: the modules bring or extend panels here, the application reaches a panel as a whole
         * and asks for a permission. Every method forwards to the entry class; this block is generated from it by
         * `composer api:manifest` and never edited by hand (`composer api:check` fails on a difference).
         *
        {$methods}
         *
         * @see \AzGuard\AzGuard
         */
        #[Api(since: '1.0')]
        final class AzGuard extends Facade
        {
            protected static function getFacadeAccessor(): string
            {
                return 'azguard';
            }
        }

        PHP;
}

/** The type of the `@return` tag, read up to the first space outside brackets, or null without the tag. */
function apiDocReturn(string $doc): ?string
{
    if (preg_match('/@return\s+/', $doc, $tag, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }
    $type = '';
    $depth = 0;

    for ($cursor = $tag[0][1] + strlen($tag[0][0]); $cursor < strlen($doc); $cursor++) {
        $char = $doc[$cursor];

        if ($depth === 0 && ctype_space($char)) {
            break;
        }
        $depth += match ($char) {
            '<', '(', '{', '[' => 1,
            '>', ')', '}', ']' => -1,
            default => 0,
        };
        $type .= $char;
    }

    return $type === '' ? null : $type;
}

function apiDefault(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        is_bool($value) => $value ? 'true' : 'false',
        is_string($value) => "'".addcslashes($value, "'\\")."'",
        is_int($value) || is_float($value) => (string) $value,
        is_array($value) && $value === [] => '[]',
        $value instanceof UnitEnum => '\\'.$value::class.'::'.$value->name,
        default => var_export($value, true),
    };
}

/**
 * The class imports of a file, by lower-case alias.
 *
 * @return array<string, string>
 */
function apiImports(string $code): array
{
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $code, $matches, PREG_SET_ORDER);
    $imports = [];

    foreach ($matches as $match) {
        $alias = $match[2] ?? substr($match[1], (int) strrpos('\\'.$match[1], '\\'));
        $imports[strtolower($alias)] = $match[1];
    }

    return $imports;
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

if (class_exists(API_ENTRY)) {
    $path = "{$root}/".API_FACADE;
    $facade = apiFacadeSource();
    $current = is_file($path) ? (string) file_get_contents($path) : '';

    if ($current !== $facade && $mode === '--write') {
        file_put_contents($path, $facade);
        fwrite(STDOUT, 'updated '.API_FACADE."\n");
    } elseif ($current !== $facade) {
        $stale = true;
        fwrite(STDERR, API_FACADE.' is stale: its @method block is generated from '.API_ENTRY." (run composer api:manifest)\n");
    }
}

exit($stale ? 1 : 0);
