<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Scaffold;

use Illuminate\Filesystem\Filesystem;

/**
 * @internal Adds a panel provider to `azguard.panels.providers` of the published configuration file.
 *
 * The file is edited as text, so the rest of it stays as the application wrote it. A file that is not published is not
 * created, and one whose `providers` list cannot be found is left alone: the caller tells the owner what to add.
 */
final readonly class ProviderRegistration
{
    public const string ADDED = 'added';

    public const string LISTED = 'listed';

    public const string UNPUBLISHED = 'unpublished';

    public const string UNRECOGNIZED = 'unrecognized';

    public function __construct(private Filesystem $files) {}

    /**
     * @return self::ADDED|self::LISTED|self::UNPUBLISHED|self::UNRECOGNIZED
     */
    public function register(string $configFile, string $provider): string
    {
        if (! $this->files->isFile($configFile)) {
            return self::UNPUBLISHED;
        }
        $source = $this->files->get($configFile);
        $tokens = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = ['text' => $text, 'offset' => $offset];
            }
            $offset += strlen($text);
        }
        $start = null;
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($depth === 0 && $token['text'] === 'return' && ($tokens[$index + 1]['text'] ?? null) === '[') {
                $start = $index + 1;

                break;
            }

            if (in_array($token['text'], ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($token['text'], [']', ')', '}'], true)) {
                $depth--;
            }
        }
        $panels = $start === null ? null : self::arrayProperty($tokens, $start, 'panels');
        $providers = $panels === null ? null : self::arrayProperty($tokens, $panels[0], 'providers');

        if ($providers === null) {
            return self::UNRECOGNIZED;
        }
        [$open, $close, $key] = $providers;
        $entries = implode('', array_column(array_slice($tokens, $open + 1, $close - $open - 1), 'text'));

        if (preg_match('/(?<![\w\\\\])\\\\?'.preg_quote($provider, '/').'::class\b/', $entries) === 1) {
            return self::LISTED;
        }
        $edited = self::insert($source, $provider, $tokens[$open]['offset'] + 1, $tokens[$key]['offset']);
        $this->files->put($configFile, $edited);

        return self::ADDED;
    }

    /**
     * Find a literal array at one direct key; comments and nested sections cannot masquerade as that key.
     *
     * @param  list<array{text: string, offset: int}>  $tokens
     * @return array{int, int, int}|null opening bracket, closing bracket and key token
     */
    private static function arrayProperty(array $tokens, int $start, string $key): ?array
    {
        $depth = 1;
        $found = null;

        for ($index = $start + 1; $index < count($tokens); $index++) {
            $text = $tokens[$index]['text'];

            if ($depth === 1 && in_array($text, ["'".$key."'", '"'.$key.'"'], true)
                && ($tokens[$index + 1]['text'] ?? null) === '=>' && ($tokens[$index + 2]['text'] ?? null) === '[') {
                $found = [$index + 2, $index];
            }

            if (in_array($text, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [']', ')', '}'], true)) {
                $depth--;

                if ($found !== null && $depth === 1) {
                    return [$found[0], $index, $found[1]];
                }

                if ($depth === 0) {
                    return null;
                }
            }
        }

        return null;
    }

    private static function insert(string $source, string $provider, int $open, int $key): string
    {
        $line = $provider.'::class,';

        // `'providers' => [],` becomes a list of one entry.
        if (preg_match('/\G\s*\]/', $source, $closed, 0, $open) === 1) {
            $indent = self::indentOf($source, $key);

            return substr($source, 0, $open)."\n".$indent.'    '.$line."\n".$indent.']'.substr($source, $open + strlen($closed[0]));
        }

        // The entries are indented like the first line after the bracket; a commented placeholder counts.
        $indent = preg_match('/\G[ \t]*\R([ \t]*)\S/', $source, $next, 0, $open) === 1
            ? $next[1]
            : self::indentOf($source, $key).'    ';

        return substr($source, 0, $open)."\n".$indent.$line.substr($source, $open);
    }

    private static function indentOf(string $source, int $offset): string
    {
        $start = strrpos(substr($source, 0, $offset), "\n");
        $line = substr($source, $start === false ? 0 : $start + 1, $offset - ($start === false ? 0 : $start + 1));

        return strlen($line) - strlen(ltrim($line)) > 0 ? substr($line, 0, strlen($line) - strlen(ltrim($line))) : '';
    }
}
