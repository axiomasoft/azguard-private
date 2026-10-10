<?php

declare(strict_types=1);

namespace AzGuard\Sources\Folder;

use AzGuard\Attributes\AsSource;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DuplicatePolicyBindingException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidPolicyStructureException;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Policies\Decides;
use AzGuard\Policies\PolicyFor;
use AzGuard\Roles\BaseRole;
use BackedEnum;
use FilesystemIterator;
use Illuminate\Contracts\Container\Container;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use SplFileInfo;
use UnitEnum;

/**
 * Finds permission enums, policies, roles, scopes and named sources in the folders of a panel.
 *
 * A root is the directory of the panel provider, or a directory passed to `discover()`. Roots keep
 * the layer that added them. The same relative group in two roots is not one group. When the recorded
 * file hashes still match, the classes are taken from the catalog cache and the folders are not parsed.
 */
final class PanelDiscovery
{
    /** How many times folders were parsed, rather than restored from a matching cache entry. */
    public static int $parsed = 0;

    /**
     * @param  array<mixed>|null  $cached  discovery stored for this panel by the current build, or null
     */
    public static function resolve(PanelRecipe $recipe, Container $container, ?array $cached): DiscoverySnapshot
    {
        $names = self::names($recipe, $container);
        $roots = self::roots($recipe);
        $collected = self::collect($roots, $names);
        $restored = is_array($cached) ? DiscoverySnapshot::fromCache($cached) : null;

        if ($restored !== null && $restored->names === $names && $restored->roots === $roots && $restored->files === $collected['files']) {
            return $restored;
        }

        self::$parsed++;

        return self::parse($recipe->panelId(), $names, $roots, $collected);
    }

    /**
     * @return array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string}
     *
     * @throws InvalidConfigurationException
     * @throws DefinitionException
     */
    public static function names(PanelRecipe $recipe, Container $container): array
    {
        $names = $container->make(AzGuardConfig::class)->discovery();
        $configured = FolderSource::configured($recipe);

        if ($configured !== null) {
            foreach ($configured['source']->folderNames() as $key => $value) {
                if (is_string($value) && $value !== '') {
                    $names[$key] = trim($value, '/');
                }
            }
        }

        self::separate($recipe->panelId(), $names['permissions'], $names['policies']);

        return $names;
    }

    /**
     * @return list<array{path: string, namespace: ?string, origin: string}>
     *
     * @throws DefinitionException
     */
    private static function roots(PanelRecipe $recipe): array
    {
        $roots = [];
        $provider = $recipe->providerClass();

        if (is_string($provider) && class_exists($provider)) {
            $file = (new ReflectionClass($provider))->getFileName();

            if (is_string($file)) {
                $path = realpath(dirname($file));
                $roots[] = [
                    'path' => $path === false ? dirname($file) : $path,
                    'namespace' => (new ReflectionClass($provider))->getNamespaceName(),
                    'origin' => PanelRecipe::PROVIDER,
                ];
            }
        }

        foreach ($recipe->layered(PanelRecipe::DISCOVER) as $record) {
            $origin = $record['origin'];
            $label = $origin['kind'] === PanelRecipe::PLUGIN ? PanelRecipe::PLUGIN.':'.$origin['plugin'] : $origin['kind'];

            foreach (is_array($record['value']) ? $record['value'] : [] as $root) {
                if (! is_array($root) || ! is_string($root['path'] ?? null)) {
                    continue;
                }

                $path = realpath($root['path']);

                if ($path === false || ! is_dir($path)) {
                    throw new DefinitionException(
                        'Panel "'.$recipe->panelId().'" discovers '.json_encode($root['path']).', which is not a directory.',
                    );
                }

                $namespace = $root['namespace'] ?? null;
                $roots[] = [
                    'path' => $path,
                    'namespace' => is_string($namespace) && $namespace !== '' ? trim($namespace, '\\') : null,
                    'origin' => $label,
                ];
            }
        }

        return $roots;
    }

    /**
     * @param  list<array{path: string, namespace: ?string, origin: string}>  $roots
     * @param  array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string}  $names
     * @return array{files: array<string, string>, classes: list<array{root: array{path: string, namespace: ?string, origin: string}, kind: string, file: string}>, sources: list<string>}
     */
    private static function collect(array $roots, array $names): array
    {
        $files = [];
        $classes = [];
        $sources = [];

        foreach ($roots as $root) {
            foreach (['permissions', 'policies', 'roles', 'scopes'] as $kind) {
                $directory = $root['path'].'/'.$names[$kind];
                $recursive = $kind === 'permissions' || $kind === 'policies';

                foreach (self::phpFiles($directory, $recursive) as $file) {
                    $files[$file] = hash('sha256', (string) file_get_contents($file));
                    $classes[] = ['root' => $root, 'kind' => $kind, 'file' => $file];
                }
            }

            foreach (self::phpFiles($root['path'].'/Sources', false) as $file) {
                $files[$file] = hash('sha256', (string) file_get_contents($file));
                $sources[] = $file;
            }

            if ($root['origin'] === PanelRecipe::PROVIDER) {
                foreach (self::phpFiles(dirname($root['path']).'/'.$names['shared'].'/Sources', false) as $file) {
                    $files[$file] = hash('sha256', (string) file_get_contents($file));
                    $sources[] = $file;
                }
            }
        }

        ksort($files, SORT_STRING);

        return ['files' => $files, 'classes' => $classes, 'sources' => $sources];
    }

    /**
     * @param  array{permissions: string, policies: string, roles: string, scopes: string, abilities: string, queries: string, shared: string}  $names
     * @param  list<array{path: string, namespace: ?string, origin: string}>  $roots
     * @param  array{files: array<string, string>, classes: list<array{root: array{path: string, namespace: ?string, origin: string}, kind: string, file: string}>, sources: list<string>}  $collected
     *
     * @throws DefinitionException
     * @throws InvalidPolicyStructureException
     * @throws DuplicatePolicyBindingException
     */
    private static function parse(string $panel, array $names, array $roots, array $collected): DiscoverySnapshot
    {
        /** @var array<string, list<array{class: class-string, path: string, group: string, origin: string}>> $enums */
        $enums = [];
        /** @var array<string, list<array{class: class-string, path: string, group: string, origin: string, for: ?string}>> $policies */
        $policies = [];
        $roles = $scopes = $definitions = $granted = [];

        foreach ($collected['classes'] as ['root' => $root, 'kind' => $kind, 'file' => $file]) {
            $resolved = self::classIn($file, $root['path'], $root['namespace']);

            if ($resolved === null) {
                continue;
            }

            $group = self::group($file, $root['path'].'/'.$names[$kind]);
            $key = $root['origin']."\0".$root['path']."\0".$group;

            if ($kind === 'permissions' && $resolved['kind'] === 'enum') {
                $enum = $resolved['class'];

                if (! is_subclass_of($enum, BackedEnum::class) || (new ReflectionEnum($enum))->getBackingType()?->getName() !== 'string') {
                    continue;
                }

                $enums[$key][] = ['class' => $enum, 'path' => $file, 'group' => $group, 'origin' => $root['origin']];

                foreach (AttributeReader::rows($enum, $group === '' ? null : $group) as $row) {
                    $definitions[] = $row;

                    if ($row['granted_to_all']) {
                        $granted[] = $row['local'];
                    }
                }
            }

            if ($kind === 'policies' && $resolved['kind'] === 'class') {
                $policyFor = self::policyFor($resolved['class'], $panel);
                $policies[$key][] = [
                    'class' => $resolved['class'], 'path' => $file, 'group' => $group, 'origin' => $root['origin'], 'for' => $policyFor,
                ];
            }

            if ($kind === 'roles' && $resolved['kind'] === 'class' && is_subclass_of($resolved['class'], BaseRole::class)) {
                $roles[] = $resolved['class'];
            }

            if ($kind === 'scopes' && $resolved['kind'] === 'class' && is_subclass_of($resolved['class'], AssignmentScopeDefinition::class)) {
                $scopes[] = $resolved['class'];
            }
        }

        $bindings = self::pair($panel, $enums, $policies);
        $sourceClasses = [];

        foreach ($collected['sources'] as $file) {
            $resolved = self::classIn($file, dirname($file), null);

            if ($resolved === null || $resolved['kind'] !== 'class') {
                continue;
            }

            if ((new ReflectionClass($resolved['class']))->getAttributes(AsSource::class) !== []) {
                $sourceClasses[] = $resolved['class'];
            }
        }

        $enumClasses = [];

        foreach ($definitions as $row) {
            $enumName = $row['case']['enum'];

            if (class_exists($enumName)) {
                $enumClasses[$enumName] = $enumName;
            }
        }

        return new DiscoverySnapshot(
            names: $names,
            roots: $roots,
            files: $collected['files'],
            definitions: $definitions,
            roles: array_values(array_unique($roles)),
            scopes: array_values(array_unique($scopes)),
            bindings: $bindings,
            grantedToAll: array_values(array_unique($granted)),
            sources: array_values(array_unique($sourceClasses)),
            enums: array_values($enumClasses),
            fromCache: false,
        );
    }

    /**
     * @param  array<string, list<array{class: class-string, path: string, group: string, origin: string}>>  $enums
     * @param  array<string, list<array{class: class-string, path: string, group: string, origin: string, for: ?string}>>  $policies
     * @return list<array{permission: string|array{enum: string, name: string}, policy: class-string, method: string, roots: list<string>}>
     *
     * @throws InvalidPolicyStructureException
     * @throws DuplicatePolicyBindingException
     * @throws DefinitionException
     */
    private static function pair(string $panel, array $enums, array $policies): array
    {
        $bindings = [];
        $bound = [];
        $groups = array_unique([...array_keys($enums), ...array_keys($policies)]);

        foreach ($groups as $key) {
            $groupEnums = $enums[$key] ?? [];
            $explicit = [];
            $unbound = [];

            foreach ($policies[$key] ?? [] as $policy) {
                if ($policy['for'] === null) {
                    $unbound[] = $policy;
                } else {
                    $explicit[] = $policy;
                }
            }

            if (count($groupEnums) === 1 && count($unbound) === 1) {
                self::methods($panel, $unbound[0], $groupEnums[0]['class'], $bindings, $bound);
            } elseif (count($unbound) > 1 || (count($groupEnums) > 1 && count($unbound) > 0)) {
                $paths = [...array_column($groupEnums, 'path'), ...array_column($unbound, 'path')];
                $group = $groupEnums[0]['group'] ?? $unbound[0]['group'];
                $origin = $groupEnums[0]['origin'] ?? $unbound[0]['origin'];

                throw new InvalidPolicyStructureException(
                    'Panel "'.$panel.'" cannot pair the group "'.$group.'" of '.$origin.' ('.implode(', ', $paths)
                    .'): more than one enum or policy has no #[PolicyFor]. Name the enum on the policy.',
                );
            } elseif (count($unbound) === 1) {
                self::methods($panel, $unbound[0], null, $bindings, $bound);
            }

            foreach ($explicit as $policy) {
                self::methods($panel, $policy, $policy['for'], $bindings, $bound);
            }
        }

        return $bindings;
    }

    /**
     * @param  array{class: class-string, path: string, group: string, origin: string, for: ?string}  $policy
     * @param  list<array{permission: string|array{enum: string, name: string}, policy: class-string, method: string, roots: list<string>}>  $bindings
     * @param  array<string, string>  $bound  local name => policy class that already binds it
     *
     * @throws InvalidPolicyStructureException
     * @throws DuplicatePolicyBindingException
     * @throws DefinitionException
     */
    private static function methods(string $panel, array $policy, ?string $enum, array &$bindings, array &$bound): void
    {
        $class = $policy['class'];
        $seen = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class || str_starts_with($method->getName(), '__')) {
                continue;
            }

            foreach ($method->getAttributes(Decides::class) as $attribute) {
                $target = self::target($attribute->newInstance(), $enum, $class, $method->getName(), $policy['path']);
                $local = $target['local'];

                if (isset($seen[$local])) {
                    throw new InvalidPolicyStructureException(
                        $class.'::'.$seen[$local].' and '.$class.'::'.$method->getName().' both decide "'.$local
                        .'": one permission is decided by one method ('.$policy['path'].').',
                    );
                }

                $seen[$local] = $method->getName();
                $owner = $bound[$local] ?? null;

                if ($owner !== null) {
                    throw new DuplicatePolicyBindingException(
                        'Permission "'.$local.'" of panel "'.$panel.'" is bound to '.$owner.' and to '.$class
                        .' ('.$policy['origin'].', '.$policy['path'].'): bind a permission to one policy.',
                    );
                }

                $bound[$local] = $class;
                $bindings[] = [
                    'permission' => $target['stored'],
                    'policy' => $class,
                    'method' => $method->getName(),
                    'roots' => [$policy['origin']],
                ];
            }
        }
    }

    /**
     * @return array{local: string, stored: string|array{enum: string, name: string}}
     *
     * @throws InvalidPolicyStructureException
     * @throws DefinitionException
     */
    private static function target(Decides $decides, ?string $enum, string $class, string $method, string $path): array
    {
        $permission = $decides->permission;

        if ($permission instanceof UnitEnum) {
            if (! $permission instanceof BackedEnum || ! is_string($permission->value)) {
                throw new DefinitionException($class.'::'.$method.' decides '.$permission::class.', which is not a string-backed case.');
            }

            if ($enum !== null && $permission::class !== $enum) {
                throw new InvalidPolicyStructureException(
                    $class.'::'.$method.' decides '.$permission::class.'::'.$permission->name.', which is outside '.$enum
                    .' ('.$path.'): #[Decides] names a case of the paired enum, or #[PolicyFor] names that enum.',
                );
            }

            if ($enum === null) {
                throw new InvalidPolicyStructureException(
                    $class.'::'.$method.' decides '.$permission::class.'::'.$permission->name.' but '.$class
                    .' is not paired with an enum ('.$path.'): add #[PolicyFor] or one enum in the same group.',
                );
            }

            return ['local' => $permission->value, 'stored' => ['enum' => $permission::class, 'name' => $permission->name]];
        }

        if ($enum === null || ! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidPolicyStructureException(
                $class.'::'.$method.' decides '.json_encode($permission).' but '.$class.' is not paired with an enum ('.$path.').',
            );
        }

        foreach ((new ReflectionEnum($enum))->getCases() as $case) {
            if ($case->getBackingValue() === $permission) {
                return ['local' => $permission, 'stored' => ['enum' => $enum, 'name' => $case->getName()]];
            }
        }

        throw new InvalidPolicyStructureException(
            $class.'::'.$method.' decides '.json_encode($permission).', which is not a case of '.$enum.' ('.$path.').',
        );
    }

    /**
     * @param  class-string  $class
     *
     * @throws DefinitionException
     */
    private static function policyFor(string $class, string $panel): ?string
    {
        $attributes = (new ReflectionClass($class))->getAttributes(PolicyFor::class);

        if ($attributes === []) {
            return null;
        }

        $enum = $attributes[0]->newInstance()->permissions;

        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class) || (new ReflectionEnum($enum))->getBackingType()?->getName() !== 'string') {
            throw new DefinitionException(
                'Panel "'.$panel.'": '.$class.' declares #[PolicyFor] for '.json_encode($enum).', which is not a string-backed permission enum.',
            );
        }

        return $enum;
    }

    /**
     * @return array{class: class-string, kind: string}|null
     */
    private static function classIn(string $file, string $rootPath, ?string $namespace): ?array
    {
        $declared = self::declared($file);

        if ($declared === null || $declared['abstract'] || $declared['kind'] === 'interface' || $declared['kind'] === 'trait') {
            return null;
        }

        if ($declared['short'] !== basename($file, '.php')) {
            return null;
        }

        $relative = substr($file, strlen(rtrim($rootPath, '/')) + 1);
        $relativeName = str_replace('/', '\\', substr($relative, 0, -4));
        $relativeNamespace = str_replace('/', '\\', trim(dirname(str_replace('\\', '/', $relative)), '/'));
        $relativeNamespace = $relativeNamespace === '.' ? '' : $relativeNamespace;

        if ($namespace === null) {
            $matches = $relativeNamespace === ''
                || $declared['namespace'] === $relativeNamespace
                || str_ends_with($declared['namespace'], '\\'.$relativeNamespace);
            $class = $matches ? $declared['fqcn'] : null;
        } else {
            $expected = trim($namespace.'\\'.$relativeName, '\\');
            $class = $declared['fqcn'] === $expected ? $expected : null;
        }

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $reflectionFile = $reflection->getFileName();

        if ($reflection->isAbstract() || $reflectionFile === false || realpath($reflectionFile) !== realpath($file)) {
            return null;
        }

        return ['class' => $class, 'kind' => $reflection->isEnum() ? 'enum' : 'class'];
    }

    /**
     * @return array{namespace: string, short: string, fqcn: string, abstract: bool, kind: string}|null
     */
    private static function declared(string $file): ?array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $abstract = false;

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::qualified($tokens, $index);
            }

            if ($token[0] === T_ABSTRACT) {
                $abstract = true;
            }

            if (! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            $previous = self::previous($tokens, $index);

            if (is_array($previous) && $previous[0] === T_NEW) {
                continue;
            }

            $name = self::nextName($tokens, $index);

            if ($name === null) {
                continue;
            }

            return [
                'namespace' => $namespace,
                'short' => $name,
                'fqcn' => $namespace === '' ? $name : $namespace.'\\'.$name,
                'abstract' => $abstract,
                'kind' => match ($token[0]) {
                    T_INTERFACE => 'interface',
                    T_TRAIT => 'trait',
                    T_ENUM => 'enum',
                    default => 'class',
                },
            ];
        }

        return null;
    }

    /**
     * @param  list<array<int, int|string>|string>  $tokens
     */
    private static function qualified(array $tokens, int $index): string
    {
        $name = '';

        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if ($token === ';' || $token === '{') {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= ltrim((string) $token[1], '\\');
            }

            if ($token === '\\') {
                $name .= '\\';
            }
        }

        return trim($name, '\\');
    }

    /**
     * @param  list<array<int, int|string>|string>  $tokens
     */
    private static function nextName(array $tokens, int $index): ?string
    {
        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if (is_array($token) && $token[0] === T_STRING) {
                return (string) $token[1];
            }

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * @param  list<mixed>  $tokens
     * @return array<int, mixed>|string|null
     */
    private static function previous(array $tokens, int $index): array|string|null
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private static function group(string $file, string $directory): string
    {
        $relative = substr($file, strlen(rtrim($directory, '/')) + 1);
        $group = trim(str_replace('\\', '/', dirname($relative)), '/');

        return $group === '.' ? '' : $group;
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory, bool $recursive): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        if (! $recursive) {
            foreach (glob(rtrim($directory, '/').'/*.php') ?: [] as $file) {
                if (is_file($file)) {
                    $files[] = $file;
                }
            }
        } else {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $resolved = [];

        foreach ($files as $file) {
            $path = realpath($file);
            $resolved[] = $path === false ? $file : $path;
        }

        sort($resolved, SORT_STRING);

        return $resolved;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function separate(string $panel, string $permissions, string $policies): void
    {
        $left = trim(str_replace('\\', '/', $permissions), '/');
        $right = trim(str_replace('\\', '/', $policies), '/');
        $nested = $left === $right
            || str_starts_with($left.'/', $right.'/')
            || str_starts_with($right.'/', $left.'/');

        if ($nested) {
            throw new InvalidConfigurationException(
                'Panel "'.$panel.'" uses "'.$permissions.'" for permissions and "'.$policies.'" for policies: the two folders must be different and neither may sit inside the other.',
            );
        }
    }
}
