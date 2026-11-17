<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Internal\Console\Make;

use AzGuard\DevTools\Internal\Scaffold\AtomicFileWriter;
use AzGuard\DevTools\Internal\Scaffold\Layout;
use AzGuard\DevTools\Internal\Scaffold\Place;
use AzGuard\DevTools\Internal\Scaffold\StubStore;
use AzGuard\DevTools\Internal\ScaffoldConfig;
use AzGuard\Laravel\Console\CommandSupport;
use AzGuard\Laravel\Console\InvalidCommandInput;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Packman\Attributes\Internal;
use Packman\DevTools\Scaffold\GeneratedFile;

/**
 * @internal The generators of the structure of a panel: the files a generator makes are planned first, and none is
 * written while one of them exists, unless `--force` is given.
 *
 * Folder names come from `azguard.discovery.*`, the root and the namespace from `azguard.scaffold.*`, and the code from
 * the stubs: `stubs/azguard/` of the application first, the ones of the package after. Exit codes are those of the
 * other commands: 0 done, 1 a file exists or the work failed, 2 invalid input.
 */
#[Internal]
abstract class MakeCommand extends Command
{
    use CommandSupport;

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * The files of the command, none written yet.
     *
     * @return list<GeneratedFile>
     */
    abstract protected function plan(Layout $layout, StubStore $stubs): array;

    /** Runs after the files are written: registrations and hints. */
    protected function written(Layout $layout): void {}

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $layout = new Layout(ScaffoldConfig::fromRepository($this->laravel->make('config')), $this->laravel->basePath());
            $files = $this->plan($layout, $this->stubs());

            $existing = [];
            foreach ($files as $file) {
                $path = $layout->absolute($file->relativePath);
                $this->assertWritable($path);

                if (is_file($path)) {
                    $existing[] = $file->relativePath;
                }
            }

            $force = $this->option('force') === true;

            if ($existing !== [] && ! $force) {
                $this->components->error(implode(', ', $existing).(count($existing) === 1 ? ' already exists' : ' already exist').'; nothing was written. Pass --force to overwrite.');

                return self::FAILURE;
            }

            $planned = array_map(
                static fn (GeneratedFile $file): array => [
                    'target' => $layout->absolute($file->relativePath),
                    'file' => $file,
                ],
                $files,
            );

            foreach ((new AtomicFileWriter)->write($planned) as $written) {
                $this->components->info(($written['overwritten'] ? 'Overwritten ' : 'Created ').$layout->relative($written['path']));
            }
            $this->written($layout);

            return self::SUCCESS;
        });
    }

    protected function generated(Layout $layout, string $path, string $contents): GeneratedFile
    {
        return new GeneratedFile($layout->relative($path), $contents, $this->option('force') === true);
    }

    protected function stubs(): StubStore
    {
        return new StubStore($this->laravel->basePath(), $this->files);
    }

    /**
     * The folder of a kind in the panel of `--panel` (which must have its directory) or in what panels share with
     * `--shared`: exactly one of the two options.
     */
    protected function panelOrShared(Layout $layout, string $folder, ?string $panel, bool $shared): Place
    {
        if (($panel === null) === ! $shared) {
            throw new InvalidCommandInput('Pass --panel=<Panel> to put it in a panel, or --shared to put it where panels share things.');
        }
        $place = $panel === null ? $layout->shared() : $layout->existingPanel($layout->panelName($panel));

        return $place->in($folder);
    }

    private function assertWritable(string $path): void
    {
        if (is_dir($path)) {
            throw new InvalidCommandInput($path.' is a directory.');
        }
    }
}
