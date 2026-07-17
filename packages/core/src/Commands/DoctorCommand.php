<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\OutputsStructured;
use AzGuard\Guard\AzGuardDiagnostics;
use Illuminate\Console\Command;

/**
 * Examples:
 *   php artisan guard:doctor
 *   php artisan guard:doctor --panel=app
 *   php artisan guard:doctor --json
 */
final class DoctorCommand extends Command
{
    use OutputsStructured;

    protected $signature = 'guard:doctor {--panel=} {--json : Output a machine-readable JSON payload instead of text}';

    protected $description = 'Check consistency of AzGuard enums, policies, and roles';

    public function handle(AzGuardDiagnostics $doctor): int
    {
        $panel = $this->option(key: 'panel');

        if (is_string($panel) && $panel === '') {
            $panel = null;
        }

        $result = $doctor->diagnose(panelFilter: is_string($panel) ? $panel : null);

        if ($this->wantsJson()) {
            $this->renderJsonPayload(
                errors: $result['errors'],
                warnings: $result['warnings'],
                abilities: $result['abilities'],
            );

            return $result['errors'] !== [] ? self::FAILURE : self::SUCCESS;
        }

        if ($result['abilities'] !== []) {
            $this->table(
                headers: ['Panel', 'Ability', 'Policy::method'],
                rows: array_map(
                    callback: static fn (array $row): array => [$row['panel'], $row['ability'], $row['handler']],
                    array: $result['abilities'],
                ),
            );
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        if ($result['errors'] !== []) {
            $this->error('guard:doctor: consistency errors found.');

            return self::FAILURE;
        }

        $this->info('guard:doctor: all checks passed.');

        return self::SUCCESS;
    }
}
