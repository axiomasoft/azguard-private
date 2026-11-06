<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

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

it('excludes MySQL-only regressions from the standard SQLite lane without excluding ordinary Filament tests', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    preg_match_all('/--exclude-group=[a-z-]+/', $composer['scripts']['test'], $matches);
    expect($matches[0])->toContain('--exclude-group=mysql-engines');

    $process = new Process([
        PHP_BINARY, 'vendor/bin/pest',
        'tests/Feature/Filament/Gate/ExportDownloadAuthorizationTest.php',
        'tests/Feature/Filament/Gate/StaleBulkAuthorizationTest.php',
        ...$matches[0], '--list-tests',
    ], $root, ['DB_CONNECTION' => 'sqlite']);
    $process->mustRun();
    $output = $process->getOutput();

    expect($output)->not->toContain('__pest_evaluable_it_checks_export_preflight_and_preparation_with_unbuffered_MySQL_reads')
        ->not->toContain('__pest_evaluable_it_checks_every_selected_record_with_unbuffered_MySQL_reads_before_a_bulk_callback')
        ->toContain('__pest_evaluable_it_refuses_an_export_action_invoked_without_view_any_and_creates_no_export');
});

it('excludes MySQL-only regressions in every serial SQLite entry point', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (['bin/test-changed.php', 'bin/coverage-gate.sh'] as $path) {
        expect((string) file_get_contents($root.'/'.$path))->toContain('--exclude-group=mysql-engines');
    }

    $workflow = Yaml::parseFile($root.'/.github/workflows/tests.yml');
    $runs = [];
    foreach ($workflow['jobs'] as $job) {
        foreach ($job['steps'] ?? [] as $step) {
            $run = $step['run'] ?? '';

            if (is_string($run) && str_contains($run, '--exclude-group=engines')) {
                $runs[] = $run;
            }
        }
    }

    expect($runs)->not->toBeEmpty();
    foreach ($runs as $run) {
        expect($run)->toContain('--exclude-group=mysql-engines');
    }
});
