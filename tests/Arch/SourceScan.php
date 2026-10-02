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
}
