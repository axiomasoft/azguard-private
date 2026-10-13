<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Internal\Console\Commands;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Laravel\Console\CommandSupport;
use AzGuard\Laravel\Console\InvalidCommandInput;
use AzGuard\Storage\Internal\StorageRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class StorageMigrationCommand extends Command
{
    use CommandSupport;

    protected $signature = 'azguard:storage:migration {name : Configured non-default storage}';

    protected $description = 'Generate a migration for a configured AzGuard storage';

    public function handle(StorageRegistry $storages, Filesystem $files): int
    {
        return $this->attempt(function () use ($storages, $files): int {
            $name = $this->stringArgument('name');

            if ($name === 'default') {
                throw new InvalidCommandInput('The default storage migration is loaded by AzGuard core.');
            }

            try {
                $storages->get($name);
            } catch (InvalidConfigurationException $error) {
                throw new InvalidCommandInput($error->getMessage(), previous: $error);
            }
            $directory = database_path('migrations');
            $suffix = 'create_azguard_'.$name.'_storage.php';

            if ($files->glob($directory.'/*_'.$suffix) !== []) {
                $this->components->error('A migration for storage '.$name.' already exists; it was not overwritten.');

                return self::FAILURE;
            }
            $stub = $files->get(__DIR__.'/../../../../../stubs/storage-migration.stub');
            $files->ensureDirectoryExists($directory);
            $path = $directory.'/'.date('Y_m_d_His').'_'.$suffix;
            $files->put($path, str_replace('{{ storage }}', $name, $stub));
            $this->info('Created '.$path);

            return self::SUCCESS;
        });
    }
}
