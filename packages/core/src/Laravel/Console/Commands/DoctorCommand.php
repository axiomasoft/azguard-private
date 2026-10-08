<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Diagnostics\Doctor;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\UnknownPanelException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;

/**
 * Checks the configuration, the panels, the storages, the sources and the plugins of AzGuard. Fails with exit code 1
 * when it finds at least one error, so it can guard a deployment.
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'azguard:doctor
        {--panel=* : Check only these panels (and the storages they use)}
        {--storage=* : Check only these storages (and the panels that use them)}
        {--production : Check a production deployment: catalog cache and build id}
        {--json : Print the findings as JSON}';

    /** @var string */
    protected $description = 'Check the AzGuard configuration, panels, storages, sources and plugins';

    public function handle(Doctor $doctor, Application $app): int
    {
        try {
            $context = $doctor->context(
                array_values(array_filter((array) $this->option('panel'), is_string(...))),
                array_values(array_filter((array) $this->option('storage'), is_string(...))),
                $this->option('production') === true || $app->environment('production'),
            );
        } catch (UnknownPanelException|InvalidConfigurationException $error) {
            $this->components->error($error->getMessage());

            return self::INVALID;
        }
        $findings = $doctor->run($context);
        $errors = count(array_filter($findings, static fn (DoctorFinding $finding): bool => $finding->isError()));

        if ($this->option('json') === true) {
            $this->line(json_encode(array_map(static fn (DoctorFinding $finding): array => $finding->toArray(), $findings),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $errors > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($findings === []) {
            $this->components->info('AzGuard doctor found no problems.');

            return self::SUCCESS;
        }
        $this->table(['Scope', 'Severity', 'Key', 'Message'], array_map(
            static fn (DoctorFinding $finding): array => [$finding->scope, $finding->severity->value, $finding->key, $finding->message],
            $findings,
        ));
        $summary = $errors.' error(s), '.(count($findings) - $errors).' warning(s).';
        $errors > 0 ? $this->components->error($summary) : $this->components->warn($summary);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
