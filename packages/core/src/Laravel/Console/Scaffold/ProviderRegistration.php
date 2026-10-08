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

        if (preg_match('/(?<![\w\\\\])\\\\?'.preg_quote($provider, '/').'::class\b/', $source) === 1) {
            return self::LISTED;
        }
        $edited = self::insert($source, $provider);

        if ($edited === null) {
            return self::UNRECOGNIZED;
        }
        $this->files->put($configFile, $edited);

        return self::ADDED;
    }

    private static function insert(string $source, string $provider): ?string
    {
        if (preg_match('/[\'"]panels[\'"]\s*=>\s*\[/', $source, $panels, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $from = $panels[0][1] + strlen($panels[0][0]);

        if (preg_match('/[\'"]providers[\'"]\s*=>\s*\[/', $source, $providers, PREG_OFFSET_CAPTURE, $from) !== 1) {
            return null;
        }
        $open = $providers[0][1] + strlen($providers[0][0]);
        $line = $provider.'::class,';

        // `'providers' => [],` becomes a list of one entry.
        if (preg_match('/\G\s*\]/', $source, $closed, 0, $open) === 1) {
            $indent = self::indentOf($source, $providers[0][1]);

            return substr($source, 0, $open)."\n".$indent.'    '.$line."\n".$indent.']'.substr($source, $open + strlen($closed[0]));
        }

        // The entries are indented like the first line after the bracket; a commented placeholder counts.
        $indent = preg_match('/\G[ \t]*\R([ \t]*)\S/', $source, $next, 0, $open) === 1
            ? $next[1]
            : self::indentOf($source, $providers[0][1]).'    ';

        return substr($source, 0, $open)."\n".$indent.$line.substr($source, $open);
    }

    private static function indentOf(string $source, int $offset): string
    {
        $start = strrpos(substr($source, 0, $offset), "\n");
        $line = substr($source, $start === false ? 0 : $start + 1, $offset - ($start === false ? 0 : $start + 1));

        return strlen($line) - strlen(ltrim($line)) > 0 ? substr($line, 0, strlen($line) - strlen(ltrim($line))) : '';
    }
}
