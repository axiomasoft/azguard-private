<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('selects MySQL unbuffered regressions only for MySQL and MariaDB engine lanes', function (string $driver, int $expected): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([
        PHP_BINARY, 'vendor/bin/pest',
        'tests/Feature/Filament/Gate/ExportDownloadAuthorizationTest.php',
        'tests/Feature/Filament/Gate/StaleBulkAuthorizationTest.php',
        '--group=engines', '--list-tests',
    ], $root, ['DB_CONNECTION' => $driver]);
    $process->mustRun();
    $output = $process->getOutput();

    expect(substr_count($output, '__pest_evaluable_it_checks_export_preflight_and_preparation_with_unbuffered_MySQL_reads'))->toBe($expected === 0 ? 0 : 4)
        ->and(substr_count($output, '__pest_evaluable_it_checks_every_selected_record_with_unbuffered_MySQL_reads_before_a_bulk_callback'))->toBe($expected === 0 ? 0 : 2);
})->with(['postgresql' => ['pgsql', 0], 'mysql' => ['mysql', 6], 'mariadb' => ['mariadb', 6], 'sqlite' => ['sqlite', 0]]);
