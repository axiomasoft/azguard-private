<?php

declare(strict_types=1);

namespace AzGuard\Audit\Internal\Console;

use AzGuard\AzGuard;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Laravel\Console\CommandSupport;
use AzGuard\Laravel\Console\InvalidCommandInput;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * @internal `azguard:audit:migration {storage}`: the migration that creates the audit journal table of a configured
 * named storage. The default storage takes the published migration (`azguard-audit-migrations`).
 */
final class AuditMigrationCommand extends Command
{
    use CommandSupport;

    protected $signature = 'azguard:audit:migration {storage : Configured non-default storage}';

    protected $description = 'Generate the audit journal migration for a configured AzGuard storage';

    public function handle(AzGuard $azguard, Filesystem $files): int
    {
        return $this->attempt(function () use ($azguard, $files): int {
            $storage = $this->stringArgument('storage');

            if ($storage === 'default') {
                throw new InvalidCommandInput('The audit migration of the default storage is published with vendor:publish --tag=azguard-audit-migrations.');
            }

            try {
                $azguard->storage($storage);
            } catch (InvalidConfigurationException $error) {
                throw new InvalidCommandInput($error->getMessage(), previous: $error);
            }
            $directory = database_path('migrations');
            $suffix = 'create_azguard_'.$storage.'_audit_log.php';

            if ($files->glob($directory.'/*_'.$suffix) !== []) {
                throw new InvalidCommandInput('An audit migration for storage '.$storage.' already exists; it was not overwritten.');
            }
            $stub = $files->get(__DIR__.'/../../../stubs/audit-migration.stub');
            $files->ensureDirectoryExists($directory);
            $path = $directory.'/'.date('Y_m_d_His').'_'.$suffix;
            $files->put($path, str_replace('{{ storage }}', $storage, $stub));
            $this->info('Created '.$path);

            return self::SUCCESS;
        });
    }
}
