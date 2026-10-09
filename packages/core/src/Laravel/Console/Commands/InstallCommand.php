<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\ProviderRegistration;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Sets AzGuard up in an application: publishes the configuration, takes the connection and the type of host keys, offers
 * the first panel, lists the migrations and runs the checks.
 *
 * - An existing configuration file or `.env` key is kept unless `--force`; the chosen values are written into the
 *   published file as text, so the rest of it stays as the application wrote it.
 * - Migrations and checks boot fresh Artisan processes so they see the published configuration and providers. A
 *   pre-existing configuration cache is cleared before setup. Child commands never wait for an interactive prompt.
 * - `migrate` runs only with `--migrate`. Its exit code is the exit code of the command and stops it before the checks;
 *   after a successful `migrate` the exit code is the one of `azguard:doctor`. Without `--migrate` the checks are
 *   printed, but the exit code reports the installation steps only: the tables do not exist yet.
 */
final class InstallCommand extends Command
{
    use InteractsWithAzGuard;

    private const array HOST_KEYS = ['string', 'bigint', 'uuid', 'ulid'];

    private const string ENV_KEY = 'AZGUARD_DB_CONNECTION';

    /** @var string */
    protected $signature = 'azguard:install
        {--migrate : Run the migrations of AzGuard}
        {--force : Overwrite the published configuration and the connection in .env}
        {--connection= : The database connection of the AzGuard tables}
        {--host-keys= : The type of the keys of the host models: string, bigint, uuid or ulid}
        {--panel= : Create the first panel with this name}';

    /** @var string */
    protected $description = 'Install AzGuard: publish the configuration, choose the storage, create the first panel, migrate and check';

    private Filesystem $files;

    public function handle(Filesystem $files): int
    {
        $this->files = $files;

        return $this->attempt(function (): int {
            $hostKeys = $this->hostKeys();
            $connection = $this->connection();
            $panel = $this->panelName();

            if ($this->files->isFile($this->laravel->getCachedConfigPath())) {
                $cleared = $this->call('config:clear');

                if ($cleared !== self::SUCCESS || ! $this->files->missing($this->laravel->getCachedConfigPath())) {
                    $this->components->error('The configuration cache could not be cleared; installation was not started.');

                    return $cleared === self::SUCCESS ? self::FAILURE : $cleared;
                }
            }
            $this->publishConfig($hostKeys);
            $effectiveConnection = $this->writeConnection($connection);
            $status = $panel === null || $this->keptPanel($panel) ? self::SUCCESS : $this->call('azguard:make:panel', ['panel' => $panel]);

            if ($status !== self::SUCCESS) {
                return $status;
            }
            $this->listMigrations();

            if ($this->option('migrate') === true) {
                $migrated = $this->fresh('migrate', $effectiveConnection, $this->option('force') === true ? ['--force'] : []);

                if ($migrated !== self::SUCCESS) {
                    $this->components->error('The migrations failed with exit code '.$migrated.'; the checks were not run.');

                    return $migrated;
                }
            } else {
                $this->components->info('The migrations were not run: pass --migrate or run `php artisan migrate`.');
            }
            $doctor = $this->fresh('azguard:doctor', $effectiveConnection);

            return $this->option('migrate') === true ? $doctor : self::SUCCESS;
        });
    }

    private function hostKeys(): string
    {
        $given = $this->stringOption('host-keys');
        $default = $this->laravel->make('config')->get('azguard.ids.host_keys');
        $default = is_string($default) && in_array($default, self::HOST_KEYS, true) ? $default : 'string';

        if ($given === null && $this->input->isInteractive()) {
            $chosen = $this->choice('Which type do the keys of your models have?', self::HOST_KEYS, $default);
            $given = is_string($chosen) ? $chosen : $default;
        }
        $given ??= $default;

        return in_array($given, self::HOST_KEYS, true)
            ? $given
            : throw new InvalidCommandInput('--host-keys must be one of '.implode(', ', self::HOST_KEYS).'; "'.$given.'" is not.');
    }

    private function connection(): ?string
    {
        $given = $this->stringOption('connection');
        $names = array_map(strval(...), array_keys((array) $this->laravel->make('config')->get('database.connections', [])));

        if ($given === null && $this->input->isInteractive() && $names !== []) {
            $default = '(the default connection)';
            $chosen = $this->choice('Which database connection holds the AzGuard tables?', [$default, ...$names], $default);
            $given = is_string($chosen) && $chosen !== $default ? $chosen : null;
        }

        if ($given !== null && ! in_array($given, $names, true)) {
            throw new InvalidCommandInput('--connection "'.$given.'" is not a connection of config/database.php.');
        }

        return $given;
    }

    private function panelName(): ?string
    {
        $given = $this->stringOption('panel');

        if ($given === null && $this->input->isInteractive() && $this->confirm('Create the first panel now?', true)) {
            $name = $this->ask('Name of the panel', 'Admin');

            return is_string($name) ? $name : null;
        }

        return $given;
    }

    private function publishConfig(string $hostKeys): void
    {
        $target = $this->laravel->configPath('azguard.php');

        if ($this->files->exists($target) && $this->option('force') !== true) {
            data_get($this->files->getRequire($target), 'ids.host_keys') === $hostKeys
                ? $this->components->info('config/azguard.php exists and was kept.')
                : $this->components->warn('config/azguard.php exists and was kept: set ids.host_keys to "'.$hostKeys.'" in it yourself, or pass --force.');

            return;
        }
        $this->files->ensureDirectoryExists(dirname($target));
        $source = (string) file_get_contents(__DIR__.'/../../../../config/azguard.php');
        $edited = preg_replace_callback(
            '/([\'"]ids[\'"]\s*=>\s*\[\s*[\'"]host_keys[\'"]\s*=>\s*)([\'"])[^\'"]*\2/',
            static fn (array $match): string => $match[1]."'".$hostKeys."'",
            $source,
            1,
            $count,
        );

        if ($edited === null || $count !== 1) {
            throw new InvalidCommandInput('The configuration of the package has no ids.host_keys to set.');
        }
        $this->files->put($target, $edited);
        $this->components->info('Published config/azguard.php with ids.host_keys = "'.$hostKeys.'".');
    }

    /**
     * A second run keeps the provider of the panel instead of failing on it, so the installation stays repeatable;
     * the provider is still listed in the configuration. `--force` regenerates it.
     */
    private function keptPanel(string $panel): bool
    {
        if ($this->option('force') === true) {
            return false;
        }
        $layout = new Layout($this->laravel->make(AzGuardConfig::class), $this->laravel->basePath());
        $name = $layout->panelName($panel);
        $place = $layout->panel($name);

        if (! is_file($place->file($name.'GuardPanelProvider'))) {
            return false;
        }
        (new ProviderRegistration($this->files))->register($this->laravel->configPath('azguard.php'), $place->fqcn($name.'GuardPanelProvider'));
        $this->components->info($layout->relative($place->file($name.'GuardPanelProvider')).' exists and was kept.');

        return true;
    }

    /** The selected connection of the child processes, unless an existing .env value was kept. */
    private function writeConnection(?string $connection): ?string
    {
        if ($connection === null) {
            return null;
        }
        $env = $this->laravel->basePath('.env');
        $line = self::ENV_KEY.'='.$connection;

        if (! $this->files->isFile($env)) {
            $this->components->warn('.env was not found: add `'.$line.'` to the environment of the application.');

            return $connection;
        }
        $contents = $this->files->get($env);
        $pattern = '/^[\t ]*(?:export[\t ]+)?([\'"]?)'.self::ENV_KEY.'\1[\t ]*=.*$/m';

        if (preg_match($pattern, $contents) !== 1) {
            $this->files->put($env, ($contents === '' ? '' : rtrim($contents, "\r\n")."\n").$line."\n");
        } elseif ($this->option('force') === true) {
            $this->files->put($env, (string) preg_replace($pattern, $line, $contents, 1));
        } else {
            $this->components->warn(self::ENV_KEY.' is already set in .env and was kept: pass --force to change it.');

            return null;
        }
        $this->components->info('Set '.self::ENV_KEY.' in .env.');

        return $connection;
    }

    /** @param list<string> $options */
    private function fresh(string $command, ?string $connection, array $options = []): int
    {
        $environment = $this->option('env');
        $environmentOptions = is_string($environment) && $environment !== '' ? ['--env='.$environment] : [];
        $process = $this->laravel->make(Process::class, [
            'command' => [PHP_BINARY, $this->laravel->basePath('artisan'), $command, '--no-interaction', ...$environmentOptions, ...$options],
            'cwd' => $this->laravel->basePath(),
            // Dotenv values inherited from the already booted parent must not override the newly chosen connection.
            'env' => $connection === null ? [] : [self::ENV_KEY => $connection],
            'timeout' => 300,
        ]);

        return $process->run(function (string $type, string $output): void {
            $this->output->write($output);
        });
    }

    private function listMigrations(): void
    {
        $files = glob(__DIR__.'/../../../../database/migrations/*.php') ?: [];
        sort($files);
        $this->components->info('Migrations of AzGuard:');

        foreach ($files as $file) {
            $this->line('  '.basename($file));
        }
    }
}
