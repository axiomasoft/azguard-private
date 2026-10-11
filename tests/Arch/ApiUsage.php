<?php

declare(strict_types=1);

namespace AzGuard\Tests\Arch;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The supported surface read from the api manifests and the checks over it: I4 (modules, Filament and the generated
 * stubs use only attributed core types and their supported members) and I20 (core calls the methods of `Spi(Implement)`
 * types positionally).
 *
 * I4 is member-aware where the receiver is known from the code alone: `new X(…)`, `X::m()`, `X::NAME`, `X::$p`, and
 * `$v->m()` / `$v->p` / `$this->p->m()` where `$v` or the property `p` is typed with one class (a parameter, a promoted
 * or declared property, `$v = new X`). A receiver the token pass cannot type reports nothing; a type reference is exact.
 */
final class ApiUsage
{
    private const CALL_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    private const MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_VAR, T_STATIC];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $manifests = null;

    /**
     * Every supported type of both manifests, by lower-case FQCN.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function supported(): array
    {
        if (self::$manifests === null) {
            self::$manifests = [];

            foreach (['core', 'filament'] as $package) {
                $file = dirname(__DIR__, 2).'/packages/'.$package.'/api-manifest.json';
                $manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($manifest) || ! is_array($manifest['classes'] ?? null)) {
                    throw new RuntimeException('An api manifest lists its classes: '.$file);
                }

                foreach ($manifest['classes'] as $entry) {
                    self::$manifests[strtolower($entry['name'])] = $entry;
                }
            }
        }

        return self::$manifests;
    }

    /** The publication of a type in the manifests (`api`, `spi-implement`, `spi-call`), or null for an internal type. */
    public static function publication(string $fqcn): ?string
    {
        return self::supported()[strtolower(ltrim($fqcn, '\\'))]['publication'] ?? null;
    }

    /**
     * I4 violations of the given files: a reference to a core type that is not supported (`type`), or to a member of a
     * supported core type that the manifest of its declaring type does not list (`member`).
     *
     * @param  list<string>  $files  absolute paths
     * @param  array<string, array<string, mixed>>  $supported  supported types by lower-case FQCN, as {@see self::supported()}
     * @return list<array{file: string, reference: string, kind: string, invariant: string, owner_phase: string|null}>
     */
    public static function i4Violations(Ownership $ownership, array $files, array $supported, ?string $root = null): array
    {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/').'/';
        $violations = [];

        foreach ($files as $file) {
            $path = str_replace($root, '', $file);
            $code = (string) file_get_contents($file);
            $row = $ownership->fileRow($path, $root);
            $found = [];

            foreach (SourceScan::ownershipReferences($code) as $reference) {
                $typeRow = $ownership->typeRow($reference['value']);

                if (($typeRow['owner'] ?? null) === 'core' && self::exists($reference['value']) && ! isset($supported[strtolower($reference['value'])])) {
                    $found[strtolower($reference['value'])] = ['reference' => self::canonical($reference['value']), 'kind' => 'type', 'row' => $typeRow];
                }
            }

            foreach (self::memberReferences($code) as [$class, $member, $kind]) {
                $typeRow = $ownership->typeRow($class);

                if (($typeRow['owner'] ?? null) !== 'core' || ! self::exists($class) || ! isset($supported[strtolower($class)])) {
                    continue;
                }
                $declaring = self::declaring($class, $member, $kind);

                if ($declaring === null || ! str_starts_with($declaring, 'AzGuard\\') || ($ownership->typeRow($declaring)['owner'] ?? null) !== 'core'
                    || self::lists($supported[strtolower($declaring)] ?? null, $member, $kind)) {
                    continue;
                }
                $reference = $declaring.'::'.($kind === 'property' ? '$' : '').$member;
                $found[strtolower($reference)] = ['reference' => $reference, 'kind' => 'member', 'row' => $ownership->typeRow($declaring)];
            }

            foreach ($found as $violation) {
                $violations[] = [
                    'file' => $path, 'reference' => $violation['reference'], 'kind' => $violation['kind'], 'invariant' => 'I4',
                    'owner_phase' => Ownership::removingPhase($row, $violation['row']),
                ];
            }
        }
        usort($violations, static fn (array $a, array $b): int => [$a['file'], $a['reference']] <=> [$b['file'], $b['reference']]);

        return $violations;
    }

    /**
     * The production files I4 covers: those the oracle gives to a module or to Filament, and the stubs of core.
     *
     * @return list<string> absolute paths
     */
    public static function i4Files(Ownership $ownership): array
    {
        $root = dirname(__DIR__, 2).'/';
        $files = array_values(array_filter(
            SourceScan::files(),
            static fn (string $file): bool => in_array($ownership->fileOwner(str_replace($root, '', $file)), [...Ownership::MODULES, 'filament'], true),
        ));
        $stubs = glob($root.'packages/core/stubs/*.stub') ?: [];
        sort($stubs);

        return [...$files, ...$stubs];
    }

    /**
     * The members a file names on a receiver whose class the code states.
     *
     * @return list<array{string, string, string}> [class, member, kind (method|constant|property)], unique
     */
    public static function memberReferences(string $code): array
    {
        $tokens = self::tokens($code);
        $namespace = SourceScan::namespace($code);
        $imports = SourceScan::imports($code);
        $resolve = static fn (string $name): string => self::resolve($name, $namespace, $imports);
        $class = null;
        $parent = null;
        $variables = [];
        $properties = [];
        $found = [];

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && ! self::is($previous, T_DOUBLE_COLON) && ! self::is($previous, T_NEW)
                && self::is($next, T_STRING)) {
                $class = $resolve($next[1]);
                $parent = null;
                $properties = [];

                for ($cursor = $index + 2; isset($tokens[$cursor]) && $tokens[$cursor] !== '{'; $cursor++) {
                    if (self::is($tokens[$cursor], T_EXTENDS) && self::isName($tokens[$cursor + 1] ?? null)) {
                        $parent = $resolve($tokens[$cursor + 1][1]);
                    }
                }
            }

            if (self::is($token, T_FUNCTION)) {
                $variables = [];
            }

            // A typed variable or property: `Type $name`, `?Type $name`, with modifiers for a property.
            if (self::is($token, T_VARIABLE) && self::isName($previous) && ! self::is($previous, T_STATIC) && ! in_array($tokens[$index - 2] ?? null, ['|', '&', ')'], true)
                && ! self::is($tokens[$index - 2] ?? null, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) && ! self::is($tokens[$index - 2] ?? null, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)) {
                $type = self::special($previous[1], $class, $parent) ?? $resolve($previous[1]);
                $variables[$token[1]] = $type;
                $before = $tokens[$index - 2] ?? null;
                $before = $before === '?' ? ($tokens[$index - 3] ?? null) : $before;

                if (is_array($before) && in_array($before[0], self::MODIFIERS, true)) {
                    $properties[substr($token[1], 1)] = $type;
                }
            }

            // `$name = new Type`
            if (self::is($token, T_VARIABLE) && $next === '=' && self::is($tokens[$index + 2] ?? null, T_NEW) && self::isName($tokens[$index + 3] ?? null)) {
                $variables[$token[1]] = self::special($tokens[$index + 3][1], $class, $parent) ?? $resolve($tokens[$index + 3][1]);
            }

            if (self::is($token, T_NEW) && self::isName($next)) {
                $found[] = [self::special($next[1], $class, $parent) ?? $resolve($next[1]), '__construct', 'method'];
            }

            if (! is_array($token) || ! in_array($token[0], self::CALL_OPERATORS, true)) {
                continue;
            }
            $member = $next;
            $receiver = self::receiver($tokens, $index, $variables, $properties, $class, $parent, $resolve);

            if ($receiver === null || ! is_array($member) || ! in_array($member[0], [T_STRING, T_VARIABLE, T_CLASS], true) || $member[0] === T_CLASS) {
                continue;
            }
            $call = ($tokens[$index + 2] ?? null) === '(';

            $found[] = match (true) {
                $member[0] === T_VARIABLE => [$receiver, substr($member[1], 1), 'property'],
                $call => [$receiver, $member[1], 'method'],
                $token[0] === T_DOUBLE_COLON => [$receiver, $member[1], 'constant'],
                default => [$receiver, $member[1], 'property'],
            };
        }

        $unique = [];
        foreach ($found as $reference) {
            $unique[strtolower(implode('|', $reference))] = $reference;
        }

        return array_values($unique);
    }

    /**
     * I20 violations: calls in the given files of a method named like a method of an `Spi(Implement)` type, with a named
     * argument. The receiver is not resolved: the name alone decides, so a call is never excused by its variable.
     *
     * @param  list<string>  $files  absolute paths
     * @param  list<string>  $methods  lower-case method names of the `Spi(Implement)` types
     * @return list<string> `file:line method` per violation
     */
    public static function i20Violations(array $files, array $methods, ?string $root = null): array
    {
        $root = rtrim($root ?? dirname(__DIR__, 2), '/').'/';
        $methods = array_flip($methods);
        $violations = [];

        foreach ($files as $file) {
            $tokens = self::tokens((string) file_get_contents($file));

            foreach ($tokens as $index => $token) {
                $name = $tokens[$index + 1] ?? null;

                if (! is_array($token) || ! in_array($token[0], self::CALL_OPERATORS, true) || ! self::is($name, T_STRING)
                    || ($tokens[$index + 2] ?? null) !== '(' || ! isset($methods[strtolower($name[1])])) {
                    continue;
                }

                if (self::namedArgument($tokens, $index + 2)) {
                    $violations[] = str_replace($root, '', $file).':'.$name[2].' '.$name[1];
                }
            }
        }

        return $violations;
    }

    /**
     * The lower-case method names declared by the `Spi(Implement)` types of the manifests.
     *
     * @param  array<string, array<string, mixed>>  $supported
     * @return list<string>
     */
    public static function implementMethods(array $supported): array
    {
        $methods = [];

        foreach ($supported as $entry) {
            if ($entry['publication'] === 'spi-implement') {
                foreach ($entry['methods'] as $method) {
                    $methods[strtolower($method['name'])] = true;
                }
            }
        }
        unset($methods['__construct']);
        $names = array_keys($methods);
        sort($names);

        return $names;
    }

    /** Whether the argument list opened at `$open` passes an argument by name. */
    private static function namedArgument(array $tokens, int $open): bool
    {
        $depth = 0;

        for ($cursor = $open; isset($tokens[$cursor]); $cursor++) {
            $token = $tokens[$cursor];

            if (in_array($token, ['(', '[', '{'], true) || self::is($token, T_CURLY_OPEN) || self::is($token, T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    return false;
                }
            } elseif ($depth === 1 && in_array($tokens[$cursor - 1], ['(', ','], true) && is_array($token)
                && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $token[1]) === 1 && ($tokens[$cursor + 1] ?? null) === ':') {
                return true;
            }
        }

        return false;
    }

    /**
     * The class of the receiver left of the operator at `$index`, when the code states it.
     *
     * @param  array<string, string>  $variables
     * @param  array<string, string>  $properties
     * @param  callable(string): string  $resolve
     */
    private static function receiver(array $tokens, int $index, array $variables, array $properties, ?string $class, ?string $parent, callable $resolve): ?string
    {
        $left = $tokens[$index - 1] ?? null;

        if ($tokens[$index][0] === T_DOUBLE_COLON) {
            return self::isName($left) || self::is($left, T_STATIC) ? (self::special($left[1], $class, $parent) ?? $resolve($left[1])) : null;
        }

        if (! self::is($left, T_STRING) && ! self::is($left, T_VARIABLE)) {
            return null;
        }

        // `$this->property->member`
        if (self::is($left, T_STRING) && in_array($tokens[$index - 2][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && self::is($tokens[$index - 3] ?? null, T_VARIABLE) && $tokens[$index - 3][1] === '$this') {
            return $properties[$left[1]] ?? null;
        }

        if (self::is($left, T_VARIABLE)) {
            return $left[1] === '$this' ? null : ($variables[$left[1]] ?? null);
        }

        return null;
    }

    private static function special(string $name, ?string $class, ?string $parent): ?string
    {
        return match (strtolower($name)) {
            'self', 'static' => $class,
            'parent' => $parent,
            default => null,
        };
    }

    /** The class that declares the member a reference names, or null when the class has no such member. */
    private static function declaring(string $class, string $member, string $kind): ?string
    {
        try {
            return match ($kind) {
                'method' => (new ReflectionMethod($class, $member))->getDeclaringClass()->getName(),
                'constant' => (new ReflectionClassConstant($class, $member))->getDeclaringClass()->getName(),
                default => (new ReflectionProperty($class, $member))->getDeclaringClass()->getName(),
            };
        } catch (ReflectionException) {
            return null;
        }
    }

    /** @param array<string, mixed>|null $entry */
    private static function lists(?array $entry, string $member, string $kind): bool
    {
        if ($entry === null) {
            return false;
        }
        $names = match ($kind) {
            'method' => array_column($entry['methods'], 'name'),
            'constant' => [...array_column($entry['constants'], 'name'), ...array_column($entry['cases'] ?? [], 'name')],
            default => array_column($entry['properties'], 'name'),
        };

        return in_array(strtolower($member), array_map(strtolower(...), $names), true);
    }

    private static function exists(string $fqcn): bool
    {
        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
    }

    /** The declared spelling of a type name. */
    private static function canonical(string $fqcn): string
    {
        return (new ReflectionClass($fqcn))->getName();
    }

    private static function is(mixed $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    private static function isName(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            && ! in_array(strtolower($token[1]), ['self', 'static', 'parent', 'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'void', 'null', 'never', 'true', 'false'], true)
            || (is_array($token) && in_array(strtolower($token[1]), ['self', 'static', 'parent'], true));
    }

    /** @return list<array{int, string, int}|string> */
    private static function tokens(string $code): array
    {
        return array_values(array_filter(token_get_all($code), static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    }

    /** @param array<string, string> $imports */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        [$first] = explode('\\', $name, 2);

        if (isset($imports[strtolower($first)])) {
            return $imports[strtolower($first)].substr($name, strlen($first));
        }

        return ($namespace === '' ? '' : $namespace.'\\').$name;
    }
}
