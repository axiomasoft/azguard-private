<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Publishes the stubs of the generators to `stubs/azguard/`, where the generators take them from first. A stub that is
 * already there stays unless `--force` is given.
 */
final class StubsCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:stubs
        {--force : Overwrite the stubs that are already published}';

    /** @var string */
    protected $description = 'Publish the stubs of the AzGuard generators';

    public function handle(Filesystem $files): int
    {
        return $this->attempt(function () use ($files): int {
            $stubs = new StubStore($this->laravel->basePath(), $files);
            $result = $stubs->publish((bool) $this->option('force'));

            foreach ($result['written'] as $name) {
                $this->components->info('Published '.StubStore::PUBLISHED.'/'.$name);
            }

            foreach ($result['kept'] as $name) {
                $this->components->warn(StubStore::PUBLISHED.'/'.$name.' exists and was kept: pass --force to overwrite it.');
            }

            return self::SUCCESS;
        });
    }
}
