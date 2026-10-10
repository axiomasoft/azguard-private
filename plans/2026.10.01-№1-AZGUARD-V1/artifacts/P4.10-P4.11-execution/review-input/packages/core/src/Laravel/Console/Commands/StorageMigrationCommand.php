<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Storage\StorageRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class StorageMigrationCommand extends Command
{
    protected $signature = 'azguard:storage:migration {name : Configured non-default storage}';

    protected $description = 'Generate a migration for a configured AzGuard storage';

    public function handle(StorageRegistry $storages, Filesystem $files): int
    {
        $name = $this->argument('name');

        if ($name === 'default') {
            $this->error('The default storage migration is loaded by AzGuard core.');

            return self::FAILURE;
        }

        try {
            $storages->get($name);
        } catch (InvalidConfigurationException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $directory = database_path('migrations');
        $suffix = 'create_azguard_'.$name.'_storage.php';

        if ($files->glob($directory.'/*_'.$suffix) !== []) {
            $this->error('A migration for storage '.$name.' already exists; it was not overwritten.');

            return self::FAILURE;
        }
        $stub = $files->get(__DIR__.'/../../../../stubs/storage-migration.stub');
        $files->ensureDirectoryExists($directory);
        $path = $directory.'/'.date('Y_m_d_His').'_'.$suffix;
        $files->put($path, str_replace('{{ storage }}', $name, $stub));
        $this->info('Created '.$path);

        return self::SUCCESS;
    }
}
