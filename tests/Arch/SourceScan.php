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
    private const KINDS = ['import', 'class-constant', 'class-string', 'fqcn-string', 'plugin-id', 'table', 'reference'];

    /** Names a class-name position takes that are language keywords, not types of the codebase. */
    private const BUILTIN_TYPES = ['self', 'static', 'parent', 'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed',
        'void', 'null', 'never', 'true', 'false'];

    private const NOT_A_CALL_AFTER = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];

    /**
     * @param  string|null  $root  repository root; this repository when null
     * @return list<string> the production source roots: every packages/<name>/src and packages/core/modules/<module>/src
     */
    public static function productionRoots(?string $root = null): array
    {
        $root ??= dirname(__DIR__, 2);

        return array_values(array_merge(
            glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [],
            glob($root.'/packages/core/modules/*/src', GLOB_ONLYDIR) ?: [],
        ));
    }

    /**
     * @param  string|null  $directory  directory relative to the repository root; every production root when null
     * @param  string|null  $root  repository root; this repository when null
     * @return list<string> sorted absolute paths of the PHP files
     */
    public static function files(?string $directory = null, ?string $root = null): array
    {
        $root ??= dirname(__DIR__, 2);
        $directories = $directory === null ? self::productionRoots($root) : [$root.'/'.$directory];
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
     * @param  list<string>  $files
     * @return list<string> the files whose code has no `declare(strict_types=1)` before their first statement
     */
    public static function withoutStrictTypes(array $files): array
    {
        return array_values(array_filter($files, static function (string $file): bool {
            $tokens = self::tokens((string) file_get_contents($file));

            foreach ($tokens as $index => $token) {
                if (is_array($token) && $token[0] === T_DECLARE) {
                    $declaration = '';
                    for ($cursor = $index + 1; isset($tokens[$cursor]) && $tokens[$cursor] !== ';'; $cursor++) {
                        $declaration .= is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
                    }

                    return preg_match('/\A\(strict_types=1\)\z/', $declaration) !== 1;
                }

                if (is_array($token) && ! in_array($token[0], [T_OPEN_TAG, T_INLINE_HTML], true)) {
                    return true;
                }
            }

            return true;
        }));
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

        return self::importsOf($tokens, self::topLevelUses($tokens));
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens  significant tokens
     * @param  list<array{int, int}>  $uses  import statements to read, as returned by {@see self::topLevelUses()}
     * @return array<string, string> class imports keyed by their lower-case aliases
     */
    private static function importsOf(array $tokens, array $uses): array
    {
        $imports = [];

        foreach ($uses as [$from, $to]) {
            $statement = '';
            for ($cursor = $from + 1; $cursor < $to; $cursor++) {
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

    /**
     * @param  list<array{int, string, int}|string>  $tokens  significant tokens
     * @return list<array{int, int}> token index of `use` and of its closing `;` for every import statement of the
     *                               namespace level; a trait `use` inside a class body and a closure `use (` are not imports
     */
    private static function topLevelUses(array $tokens): array
    {
        $uses = [];
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
            $cursor = $index + 1;
            while (isset($tokens[$cursor]) && $tokens[$cursor] !== ';') {
                $cursor++;
            }
            $uses[] = [$index, $cursor];
        }

        return $uses;
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

    /**
     * The namespace blocks of a file: the part before the first `namespace` declaration and one block per declaration,
     * each with its namespace and its own imports. A name resolves in the block it stands in; an alias imported in
     * one block is not seen in the next.
     *
     * @param  list<array{int, string, int}|string>  $tokens  significant tokens
     * @return array{blocks: list<array{namespace: string, imports: array<string, string>}>, blockOf: array<int, int>} block of each token index
     */
    private static function namespaceBlocks(array $tokens): array
    {
        $starts = [0];
        $namespaces = [''];

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $next = $tokens[$index + 1] ?? null;
                $starts[] = $index;
                $namespaces[] = is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true) ? $next[1] : '';
            }
        }
        $blockOf = [];
        $block = 0;

        foreach (array_keys($tokens) as $index) {
            while (isset($starts[$block + 1]) && $index >= $starts[$block + 1]) {
                $block++;
            }
            $blockOf[$index] = $block;
        }
        $uses = array_fill(0, count($starts), []);

        foreach (self::topLevelUses($tokens) as $use) {
            $uses[$blockOf[$use[0]]][] = $use;
        }
        $blocks = [];

        foreach ($namespaces as $block => $namespace) {
            $blocks[] = ['namespace' => $namespace, 'imports' => self::importsOf($tokens, $uses[$block])];
        }

        return ['blocks' => $blocks, 'blockOf' => $blockOf];
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
     * Every way a file names a type, a plugin or a table of another owner. Kinds, strongest first: `import` (also a
     * grouped one), `class-constant` (`Foo::class`), `class-string` (a `class-string<…>` docblock type), `fqcn-string`
     * (a string literal that is a qualified class name), `plugin-id`, `table`, `reference` (any other class name).
     * A value is reported once per file, with its strongest kind.
     *
     * @param  list<string>  $pluginIds  plugin id literals to report, such as `azguard/audit`
     * @param  list<string>  $tables  table names to report, as the whole literal or after a prefix expression
     * @return list<array{kind: string, value: string}> sorted by value
     */
    public static function ownershipReferences(string $code, array $pluginIds = [], array $tables = []): array
    {
        $tokens = self::tokens($code);
        ['blocks' => $blocks, 'blockOf' => $blockOf] = self::namespaceBlocks($tokens);
        $found = [];
        $note = static function (string $kind, string $value) use (&$found): void {
            $value = ltrim($value, '\\');
            $key = strtolower($value);

            if ($value !== '' && (! isset($found[$key]) || array_search($kind, self::KINDS, true) < array_search($found[$key]['kind'], self::KINDS, true))) {
                $found[$key] = ['kind' => $kind, 'value' => $value];
            }
        };

        foreach ($blocks as $block) {
            foreach ($block['imports'] as $import) {
                $note('import', $import);
            }
        }
        $rawBlock = 0;

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $rawBlock++;
            }

            if (is_array($token) && $token[0] === T_DOC_COMMENT && preg_match_all('/class-string<([^>]*)>/', $token[1], $matches) > 0) {
                foreach ($matches[1] as $types) {
                    foreach (preg_split('/\s*[|&]\s*/', trim($types)) ?: [] as $type) {
                        if (preg_match('/\A\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*\z/', $type) === 1) {
                            $note('class-string', self::resolve($type, $blocks[$rawBlock]['namespace'], $blocks[$rawBlock]['imports']));
                        }
                    }
                }
            }

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = str_replace('\\\\', '\\', substr($token[1], 1, -1));

                if (preg_match('/\A\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+\z/', $value) === 1) {
                    $note('fqcn-string', $value);
                } elseif (in_array($value, $pluginIds, true)) {
                    $note('plugin-id', $value);
                }
            }

            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literal = $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];

                foreach ($tables as $table) {
                    if ($literal === $table) {
                        $note('table', $table);
                    }
                }
            }
        }

        $uses = array_map(static fn (array $range): array => range($range[0], $range[1]), self::topLevelUses($tokens));
        $inUse = array_flip(array_merge([], ...$uses));
        $namespaceDeclaration = null;
        ['blocks' => $blocks, 'blockOf' => $blockOf] = self::namespaceBlocks($tokens);
        $attributeDepth = 0;
        $attributeParentheses = 0;
        $inClause = false;

        foreach ($tokens as $index => $token) {
            if (is_array($token) && in_array($token[0], [T_EXTENDS, T_IMPLEMENTS, T_USE], true)) {
                $inClause = true;
            } elseif ($token === '{' || $token === ';') {
                $inClause = false;
            }

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespaceDeclaration = $index + 1;
            }

            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                [$attributeDepth, $attributeParentheses] = [1, 0];
            } elseif ($attributeDepth > 0) {
                $attributeDepth += is_string($token) ? ['[' => 1, ']' => -1][$token] ?? 0 : 0;
                $attributeParentheses += is_string($token) ? ['(' => 1, ')' => -1][$token] ?? 0 : 0;
            }

            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                || isset($inUse[$index]) || $index === $namespaceDeclaration) {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            $previousType = is_array($previous) ? $previous[0] : $previous;

            if ($token[0] === T_STRING && in_array(strtolower($token[1]), self::BUILTIN_TYPES, true)) {
                continue;
            }

            if (in_array($previousType, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)
                || ($next === '(' && $previousType !== T_NEW && ! ($attributeDepth > 0 && $attributeParentheses === 0))
                || ($next === ':' && in_array($previousType, ['(', ','], true))
                || ($token[0] === T_STRING && ! $inClause && ! ($attributeDepth > 0 && $attributeParentheses === 0)
                    && ! (($tokens[$index - 2] ?? null) !== null && is_array($tokens[$index - 2]) && $tokens[$index - 2][0] === T_CATCH)
                    && self::isConstantRead($previousType, is_array($next) ? $next[0] : $next, self::operandBefore($tokens, $index - 2)))) {
                continue;
            }
            $isClassConstant = is_array($next) && $next[0] === T_DOUBLE_COLON
                && is_array($tokens[$index + 2] ?? null) && $tokens[$index + 2][0] === T_CLASS;
            $note($isClassConstant ? 'class-constant' : 'reference', self::resolve($token[1], $blocks[$blockOf[$index]]['namespace'], $blocks[$blockOf[$index]]['imports']));
        }
        ksort($found);

        return array_values($found);
    }

    /**
     * A bare name in an expression is a constant, not a class: `return Name;`, `= Name`, `. Name`, `Name | 2`. After `(`,
     * `,` or `[` it is one only when no variable, `...`, `|`, `&`, `::` or `(` follows: `(Name $x)`, `(Name|Other $x)`,
     * `[Name::class]` name types and classes. The names of an `extends`, `implements` or trait `use` clause, of an
     * attribute list and of a `catch` are classes wherever they stand; the caller skips this test for them.
     */
    private static function isConstantRead(int|string|null $previous, int|string|null $next, int|string|null $beforeType = null): bool
    {
        // `2 | Name`, `$flags & Name`: the right operand of a bit operator whose left operand is a value, not a type.
        if (in_array($previous, ['|', '&', T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
            return in_array($beforeType, [T_LNUMBER, T_DNUMBER, T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, ')', ']'], true)
                && ! in_array($next, [T_DOUBLE_COLON, T_NS_SEPARATOR, '('], true);
        }

        if (in_array($previous, [T_RETURN, '=', T_DOUBLE_ARROW, '.', '!', T_BOOLEAN_AND, T_BOOLEAN_OR, T_IS_IDENTICAL, T_IS_EQUAL,
            T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL, '+', '-', '*', '/', '<', '>', T_COALESCE, T_ECHO, T_CASE], true)) {
            return ! in_array($next, [T_DOUBLE_COLON, T_NS_SEPARATOR, '('], true);
        }

        return in_array($previous, ['(', ',', '['], true)
            && ! in_array($next, [T_VARIABLE, T_DOUBLE_COLON, T_ELLIPSIS, T_NS_SEPARATOR, '|', '&', '(', T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true);
    }

    /**
     * The kind of token that ends the left operand of a `|` or `&` at `$index`: its token type, or `null` when it closes
     * a parenthesised type group of a DNF type, `(Left&Right)`: only names and `&`, at least one `&`, where a type may stand.
     *
     * @param  list<array{int, string, int}|string>  $tokens  significant tokens
     */
    private static function operandBefore(array $tokens, int $index): int|string|null
    {
        $token = $tokens[$index] ?? null;

        if ($token !== ')') {
            return is_array($token) ? $token[0] : $token;
        }

        $ampersand = false;

        for ($cursor = $index - 1; isset($tokens[$cursor]) && $tokens[$cursor] !== '('; $cursor--) {
            $type = is_array($tokens[$cursor]) ? $tokens[$cursor][0] : $tokens[$cursor];
            $isAmpersand = in_array($type, ['&', T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true);
            $ampersand = $ampersand || $isAmpersand;

            if (! $isAmpersand && ! in_array($type, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                return ')';
            }
        }
        $before = $tokens[$cursor - 1] ?? null;
        $beforeType = is_array($before) ? $before[0] : $before;
        $inTypePosition = match (true) {
            in_array($beforeType, [':', '|', '?', T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_FINAL, T_VAR], true) => true,
            $beforeType === '(' => self::opensParameterList($tokens, $cursor - 1),
            $beforeType === ',' => self::opensParameterList($tokens, self::enclosingOpen($tokens, $cursor - 1)),
            default => false,
        };

        return $ampersand && $inTypePosition ? null : ')';
    }

    /** Index of the `(` that encloses the token at `$index`, or `null` at the top level. */
    private static function enclosingOpen(array $tokens, int $index): ?int
    {
        $depth = 0;

        for ($cursor = $index; $cursor >= 0; $cursor--) {
            $depth += is_string($tokens[$cursor]) ? ['(' => -1, ')' => 1][$tokens[$cursor]] ?? 0 : 0;

            if ($depth < 0) {
                return $cursor;
            }
        }

        return null;
    }

    /** Whether the `(` at `$index` opens the parameter list of a function, method, closure or arrow function, not a call or a group. */
    private static function opensParameterList(array $tokens, ?int $index): bool
    {
        if ($index === null) {
            return false;
        }
        $before = $tokens[$index - 1] ?? null;
        $beforeType = is_array($before) ? $before[0] : $before;

        if (in_array($beforeType, [T_FUNCTION, T_FN], true)) {
            return true;
        }
        $earlier = $tokens[$index - 2] ?? null;
        $earlierType = is_array($earlier) ? $earlier[0] : $earlier;
        $earlierType = $earlierType === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG ? (is_array($tokens[$index - 3] ?? null) ? $tokens[$index - 3][0] : null) : $earlierType;

        return $beforeType === T_STRING && $earlierType === T_FUNCTION;
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
