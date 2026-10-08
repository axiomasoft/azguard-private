<?php

declare(strict_types=1);

namespace AzGuard\Tests\Arch;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Token scan of package sources for rules Pest arch cannot express: Pest resolves an unqualified call to a namespaced
 * function and negates "the subject uses all of the given names", so a function rule there never fails.
 */
final class SourceScan
{
    private const NOT_A_CALL_AFTER = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];

    /**
     * @param  string|null  $directory  directory relative to the repository root; every packages/<name>/src when null
     * @return list<string> sorted absolute paths of the PHP files
     */
    public static function files(?string $directory = null): array
    {
        $root = dirname(__DIR__, 2);
        $directories = $directory === null ? (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: []) : [$root.'/'.$directory];
        $files = [];

        foreach ($directories as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $functions  lower-case global function names
     * @return list<string> the given functions called as global functions in the PHP code; methods, static calls and
     *                      declarations with the same name are not calls
     */
    public static function functionCalls(string $code, array $functions): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $calls = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = strtolower(ltrim($token[1], '\\'));
            $previous = $tokens[$i - 1] ?? null;

            if (in_array($name, $functions, true) && ($tokens[$i + 1] ?? null) === '('
                && ! (is_array($previous) && in_array($previous[0], self::NOT_A_CALL_AFTER, true))) {
                $calls[] = $name;
            }
        }

        return $calls;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $functions  lower-case global function names
     * @return list<string> offenders as "<path relative to the repository root>: <function>()"
     */
    public static function callsIn(array $files, array $functions): array
    {
        $root = dirname(__DIR__, 2).'/';
        $offenders = [];

        foreach ($files as $file) {
            foreach (self::functionCalls((string) file_get_contents($file), $functions) as $call) {
                $offenders[] = str_replace($root, '', $file).': '.$call.'()';
            }
        }

        return $offenders;
    }

    /** @return array<string, string> class imports keyed by their lower-case aliases */
    public static function imports(string $code): array
    {
        $tokens = self::tokens($code);
        $imports = [];
        $depth = 0;
        $namespaceDepth = 0;

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
                    if (in_array($tokens[$cursor], ['{', ';'], true)) {
                        $namespaceDepth = $tokens[$cursor] === '{' ? $depth + 1 : $depth;

                        break;
                    }
                }
            }

            if ($token === '{') {
                $depth++;
            }

            if ($token === '}') {
                $depth--;
            }

            if (! is_array($token) || $token[0] !== T_USE || $depth !== $namespaceDepth
                || ($tokens[$index + 1] ?? null) === '(') {
                continue;
            }
            $statement = '';
            for ($cursor = $index + 1; isset($tokens[$cursor]) && $tokens[$cursor] !== ';'; $cursor++) {
                $part = $tokens[$cursor];
                $statement .= is_array($part) ? $part[1].' ' : $part;
            }
            $statement = trim($statement);

            if (preg_match('/\A(?:function|const)\b/i', $statement) === 1) {
                continue;
            }
            $prefix = '';

            if (str_contains($statement, '{')) {
                [$prefix, $statement] = explode('{', $statement, 2);
                $prefix = preg_replace('/\s+/', '', $prefix);
                $statement = rtrim(trim($statement), '}');
            }
            foreach (explode(',', $statement) as $part) {
                $parts = preg_split('/\s+as\s+/i', trim($part));

                if (preg_match('/\A(?:function|const)\b/i', $parts[0]) === 1) {
                    continue;
                }
                $name = ltrim($prefix.preg_replace('/\s+/', '', $parts[0]), '\\');
                $alias = $parts[1] ?? substr($name, (int) strrpos('\\'.$name, '\\'));
                $imports[strtolower(trim($alias))] = $name;
            }
        }

        return $imports;
    }

    public static function namespace(string $code): string
    {
        $tokens = self::tokens($code);
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $next = $tokens[$index + 1] ?? null;

                return is_array($next) ? $next[1] : '';
            }
        }

        return '';
    }

    /** @return list<string> resolved imports and class references, including fully qualified names */
    public static function references(string $code): array
    {
        $imports = self::imports($code);
        $namespace = self::namespace($code);
        $references = array_values($imports);
        foreach (self::tokens($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $references[] = self::resolve($token[1], $namespace, $imports);
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * @return array{classes: list<string>, parents: array<string, string>, calls: list<array{class: string, scope: string, method: string, line: int}>}
     */
    public static function staticCalls(string $code): array
    {
        $tokens = self::tokens($code);
        $namespace = self::namespace($code);
        $imports = self::imports($code);
        $parents = [];
        $calls = [];
        $classes = [];
        $declared = [];
        $depth = 0;
        $pending = null;

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;

            if (is_array($token) && $token[0] === T_CLASS && ! (is_array($previous) && $previous[0] === T_DOUBLE_COLON)) {
                $name = $tokens[$index + 1] ?? null;
                $pending = $namespace.'\\'.(is_array($name) && $name[0] === T_STRING ? $name[1] : 'anonymous@'.$index);
                $declared[] = $pending;
                for ($cursor = $index + 1; isset($tokens[$cursor]) && $tokens[$cursor] !== '{'; $cursor++) {
                    if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_EXTENDS) {
                        $parents[$pending] = self::resolve($tokens[$cursor + 1][1], $namespace, $imports);
                    }
                }
            }

            if ($token === '{') {
                $depth++;

                if ($pending !== null) {
                    $classes[$depth] = $pending;
                    $pending = null;
                }
            }

            if ($token === '}') {
                unset($classes[$depth]);
                $depth--;
            }
            $next = $tokens[$index + 1] ?? null;
            $method = $tokens[$index + 2] ?? null;

            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                || ! is_array($next) || $next[0] !== T_DOUBLE_COLON || ! is_array($method) || $method[0] !== T_STRING
                || ($tokens[$index + 3] ?? null) !== '(') {
                continue;
            }
            $scope = $classes === [] ? $namespace : end($classes);
            $class = match (strtolower($token[1])) {
                'self', 'static' => $scope,
                'parent' => $parents[$scope] ?? '',
                default => self::resolve($token[1], $namespace, $imports),
            };
            $calls[] = ['class' => $class, 'scope' => $scope, 'method' => $method[1], 'line' => $token[2]];
        }

        return ['classes' => $declared, 'parents' => $parents, 'calls' => $calls];
    }

    /** @param list<string> $files
     * @return list<string>
     */
    public static function modelStaticCallsIn(array $files): array
    {
        $scans = [];
        $models = [];
        $parents = [];
        foreach ($files as $file) {
            $scan = self::staticCalls((string) file_get_contents($file));
            $scans[$file] = $scan;
            foreach ($scan['parents'] as $class => $parent) {
                $parents[strtolower($class)] = strtolower($parent);
            }
        }
        foreach ($parents as $class => $parent) {
            $seen = [];
            while (! isset($seen[$parent])) {
                if (str_starts_with(strtolower($parent), 'azguard\\storage\\models\\')) {
                    $models[strtolower($class)] = true;

                    break;
                }
                $seen[$parent] = true;
                $parent = $parents[$parent] ?? '';

                if ($parent === '') {
                    break;
                }
            }
        }
        $offenders = [];
        foreach ($scans as $file => $scan) {
            foreach ($scan['calls'] as $call) {
                $class = strtolower($call['class']);

                if ((str_starts_with($class, 'azguard\\storage\\models\\') || isset($models[$class]))
                    && ! self::inZone($call['scope'], ['AzGuard\\Storage', 'AzGuard\\Sources\\Database'])) {
                    $offenders[] = $file.':'.$call['line'].' '.$call['class'].'::'.$call['method'].'()';
                }
            }
        }

        return $offenders;
    }

    /** @param list<string> $zones */
    public static function inZone(string $name, array $zones): bool
    {
        foreach ($zones as $zone) {
            if (strcasecmp($name, $zone) === 0 || str_starts_with(strtolower($name), strtolower($zone).'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $dependencies
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function restrictedReferencesIn(array $files, array $dependencies, array $allowed): array
    {
        $offenders = [];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);

            if (self::inZone(self::namespace($code), $allowed)) {
                continue;
            }
            $classes = self::staticCalls($code)['classes'];

            if ($classes !== [] && array_filter($classes, static fn (string $class): bool => ! self::inZone($class, $allowed)) === []) {
                continue;
            }
            foreach (self::references($code) as $reference) {
                if (self::inZone($reference, $dependencies)) {
                    $offenders[] = $file.': '.$reference;
                }
            }
        }

        return $offenders;
    }

    /**
     * @return list<string>
     */
    public static function transactionCalls(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $calls = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'transaction') {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            $called = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

            if ($called && $next === '(') {
                $calls[] = 'transaction';
            }
        }

        return $calls;
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

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return $namespace.'\\'.substr($name, 10);
        }
        [$first] = explode('\\', $name, 2);

        if (isset($imports[strtolower($first)])) {
            return $imports[strtolower($first)].substr($name, strlen($first));
        }

        return ($namespace === '' ? '' : $namespace.'\\').$name;
    }
}
