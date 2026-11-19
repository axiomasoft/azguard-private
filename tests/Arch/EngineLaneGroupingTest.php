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

it('uses the driver exclusions in the SQLite entry points', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ([$composer['scripts']['test:parallel'], file_get_contents($root.'/bin/test-changed.php'), file_get_contents($root.'/bin/coverage-gate.sh')] as $entryPoint) {
        expect($entryPoint)->toContain('--exclude-group=mysql-engines')->toContain('--exclude-group=pg');
    }

    $workflow = Yaml::parseFile($root.'/.github/workflows/tests.yml');
    expect(array_column($workflow['jobs']['test']['steps'], 'run'))->toContain('composer test:parallel -- --ci')
        ->and(array_column($workflow['jobs']['coverage']['steps'], 'run'))->toContain('bash bin/coverage-gate.sh --ci --exclude-group=extended --coverage-clover=coverage.xml');
});

it('runs the PostgreSQL integration in the matching server lane', function (string $driver, int $expected): void {
    $process = new Process([
        PHP_BINARY, 'bin/test-db.php', $driver, 'tests/Feature/Extensions/PostgresIntegrationTest.php', '--list-tests',
    ], dirname(__DIR__, 2));
    $process->mustRun();

    expect(substr_count($process->getOutput(), '__pest_evaluable_it_runs_AzGuard_core_migrations'))->toBe($expected);
})->with(['pgsql' => ['pgsql', 1], 'mysql' => ['mysql', 0], 'mariadb' => ['mariadb', 0]]);
