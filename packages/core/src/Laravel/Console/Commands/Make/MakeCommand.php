<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands\Make;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use AzGuard\Laravel\Console\Scaffold\GeneratedFile;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\Place;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal The generators of the structure of a panel: the files a generator makes are planned first, and none is
 * written while one of them exists, unless `--force` is given.
 *
 * Folder names come from `azguard.discovery.*`, the root and the namespace from `azguard.scaffold.*`, and the code from
 * the stubs: `stubs/azguard/` of the application first, the ones of the package after. Exit codes are those of the
 * other commands: 0 done, 1 a file exists or the work failed, 2 invalid input.
 */
abstract class MakeCommand extends GeneratorCommand
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $type = 'Class';

    /** The stub that names the command in messages; the files may use more. */
    abstract protected function stubName(): string;

    /**
     * The files of the command, none written yet.
     *
     * @return list<GeneratedFile>
     */
    abstract protected function plan(Layout $layout, StubStore $stubs): array;

    /** Runs after the files are written: registrations and hints. */
    protected function written(Layout $layout): void {}

    /**
     * Generation runs in {@see self::execute()}, which returns the exit code; the generator contract of the framework
     * returns a flag, and a flag would make a refusal exit with 0.
     */
    public function handle(): ?bool
    {
        return null;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->attempt(function (): int {
            $layout = new Layout($this->laravel->make(AzGuardConfig::class), $this->laravel->basePath());
            $files = $this->plan($layout, $this->stubs());

            $existing = [];
            foreach ($files as $file) {
                $this->assertWritable($file);

                if (is_file($file->path)) {
                    $existing[] = $layout->relative($file->path);
                }
            }

            if ($existing !== [] && ! $this->option('force')) {
                $this->components->error(implode(', ', $existing).(count($existing) === 1 ? ' already exists' : ' already exist').'; nothing was written. Pass --force to overwrite.');

                return self::FAILURE;
            }

            foreach ($files as $file) {
                $overwritten = is_file($file->path);
                $this->makeDirectory($file->path);
                $this->files->put($file->path, $file->contents);
                $this->components->info(($overwritten ? 'Overwritten ' : 'Created ').$layout->relative($file->path));
            }
            $this->written($layout);

            return self::SUCCESS;
        });
    }

    protected function getStub(): string
    {
        return $this->stubs()->path($this->stubName());
    }

    /** @return array<string, string> */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [];
    }

    protected function stubs(): StubStore
    {
        return new StubStore($this->laravel->basePath(), $this->files);
    }

    protected function file(Place $place, string $class, string $contents): GeneratedFile
    {
        return new GeneratedFile($place->file($class), $contents);
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

    private function assertWritable(GeneratedFile $file): void
    {
        if (is_dir($file->path)) {
            throw new InvalidCommandInput($file->path.' is a directory.');
        }
    }
}
