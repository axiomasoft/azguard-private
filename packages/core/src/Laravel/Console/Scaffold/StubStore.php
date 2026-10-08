<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Scaffold;

use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use Illuminate\Filesystem\Filesystem;

/**
 * @internal The stubs of the generators: the ones published to `stubs/azguard/` win over the ones of the package.
 *
 * A stub holds `{{ name }}` placeholders and is plain PHP otherwise. A placeholder nothing fills is an error: a
 * published stub from an older release would otherwise reach the application with the placeholder in its code.
 */
final readonly class StubStore
{
    public const string PUBLISHED = 'stubs/azguard';

    public function __construct(private string $basePath, private Filesystem $files) {}

    public static function packaged(): string
    {
        return dirname(__DIR__, 4).'/stubs';
    }

    public function published(): string
    {
        return rtrim($this->basePath, '/').'/'.self::PUBLISHED;
    }

    /** The file of a stub: the published one when the application has it. */
    public function path(string $name): string
    {
        $published = $this->published().'/'.$name.'.stub';

        return $this->files->isFile($published) ? $published : self::packaged().'/'.$name.'.stub';
    }

    /**
     * @param  array<string, string>  $values
     */
    public function render(string $name, array $values): string
    {
        $path = $this->path($name);

        if (! $this->files->isFile($path)) {
            throw new InvalidCommandInput('The stub '.$name.' does not exist: '.$path.'.');
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z]\w*)\s*\}\}/',
            static fn (array $match): string => $values[$match[1]]
                ?? throw new InvalidCommandInput('The stub '.$path.' has the placeholder {{ '.$match[1].' }}, which this release does not fill: publish the stubs again with azguard:stubs --force.'),
            $this->files->get($path),
        );
    }

    /**
     * Copies the stubs of the package to `stubs/azguard/`; one that is already there stays unless `$force`.
     *
     * @return array{written: list<string>, kept: list<string>}
     */
    public function publish(bool $force): array
    {
        $written = $kept = [];
        $this->files->ensureDirectoryExists($this->published());

        foreach ($this->files->glob(self::packaged().'/*.stub') ?: [] as $stub) {
            $target = $this->published().'/'.basename($stub);

            if ($this->files->exists($target) && ! $force) {
                $kept[] = basename($stub);

                continue;
            }
            $this->files->copy($stub, $target);
            $written[] = basename($stub);
        }
        sort($written);
        sort($kept);

        return ['written' => $written, 'kept' => $kept];
    }

    /**
     * `use` lines of the classes, in the order Pint keeps them: by name, a namespace separator before any letter.
     *
     * @param  list<string>  $classes
     */
    public static function imports(array $classes): string
    {
        $classes = array_values(array_unique(array_map(static fn (string $class): string => ltrim($class, '\\'), $classes)));
        usort($classes, static fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

        return implode("\n", array_map(static fn (string $class): string => 'use '.$class.';', $classes));
    }
}
