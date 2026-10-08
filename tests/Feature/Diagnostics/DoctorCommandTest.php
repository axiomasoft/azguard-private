<?php

declare(strict_types=1);

use AzGuard\Catalog\CatalogCache;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Diagnostics\ProbeCheck;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Support\Facades\Artisan;

/*
 * `azguard:doctor [--panel=] [--storage=] [--production] [--json]`: a table by scope or a stable JSON list, exit 1
 * when there is an error, 0 otherwise.
 */

afterEach(function (): void {
    CrmWorld::resetRuntime();
});

/** @param list<Closure(): iterable<mixed>> $checks */
function commandPanel(array $checks = []): void
{
    DoctorWorld::migrate();
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)
        ->permissions([OrderPermission::class, DatabaseSource::make()])
        ->doctorChecks(array_map(static fn (Closure $run): ProbeCheck => new ProbeCheck('probe.command', $run), $checks))]);
}

it('exits 0 and says so when it finds nothing', function (): void {
    commandPanel();

    expect(Artisan::call('azguard:doctor'))->toBe(0)
        ->and(Artisan::output())->toContain('AzGuard doctor found no problems.');
});

it('prints a table by scope and exits 1 when a finding is an error, 0 when all are warnings', function (): void {
    commandPanel([static fn (): array => [DoctorFinding::warning('probe.command', 'Only a warning.')]]);
    expect(Artisan::call('azguard:doctor'))->toBe(0)
        ->and(Artisan::output())->toContain('panel:test', 'warning', 'probe.command', 'Only a warning.', '0 error(s), 1 warning(s).');

    commandPanel([static fn (): array => [DoctorFinding::error('probe.command', 'A real problem.')]]);
    expect(Artisan::call('azguard:doctor'))->toBe(1)
        ->and(Artisan::output())->toContain('A real problem.', '1 error(s), 0 warning(s).');
});

it('prints the findings as a JSON list with the same exit code', function (): void {
    commandPanel([static fn (): array => [DoctorFinding::error('probe.command', 'A real problem.', details: ['b' => 2, 'a' => [1, 'x']])]]);

    expect(Artisan::call('azguard:doctor', ['--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            ['key' => 'probe.command', 'severity' => 'error', 'scope' => 'panel:test', 'message' => 'A real problem.', 'details' => ['a' => [1, 'x'], 'b' => 2]],
        ]);
});

it('checks only the selected panels and storages and refuses unknown ones with exit 2', function (): void {
    commandPanel([static fn (): array => [DoctorFinding::error('probe.command', 'A real problem.')]]);

    expect(Artisan::call('azguard:doctor', ['--panel' => ['test'], '--json' => true]))->toBe(1)
        ->and(Artisan::call('azguard:doctor', ['--storage' => ['default'], '--json' => true]))->toBe(1)
        ->and(Artisan::call('azguard:doctor', ['--panel' => ['ghost']]))->toBe(2)
        ->and(Artisan::output())->toContain('ghost')
        ->and(Artisan::call('azguard:doctor', ['--storage' => ['ghost']]))->toBe(2);
});

it('checks a production deployment with --production or in the production environment', function (): void {
    commandPanel();
    config(['azguard.catalog.cache_path' => sys_get_temp_dir().'/azguard-doctor-missing-'.bin2hex(random_bytes(4)).'.php']);
    app()->forgetInstance(AzGuardConfig::class);
    app()->forgetInstance(CatalogCache::class);
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)->permissions([OrderPermission::class, DatabaseSource::make()])]);
    $keys = static fn (): array => array_column(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), 'key');

    Artisan::call('azguard:doctor', ['--json' => true]);
    expect($keys())->toBe([]);

    Artisan::call('azguard:doctor', ['--json' => true, '--production' => true]);
    expect($keys())->toBe(['catalog.build_id', 'catalog.cached']);

    app()->detectEnvironment(static fn (): string => 'production');
    Artisan::call('azguard:doctor', ['--json' => true]);
    expect($keys())->toBe(['catalog.build_id', 'catalog.cached']);
});

it('matches the JSON snapshot of the CRM stand and changes nothing it reads', function (): void {
    CrmWorld::seed();
    CrmWorld::compile();
    $storage = CrmWorld::storage();
    $before = [$storage->state('crm')?->version, $storage->table('role_grants')->count(), $storage->table('permission_grants')->count()];

    expect(Artisan::call('azguard:doctor', ['--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Diagnostics/doctor.json'), true, flags: JSON_THROW_ON_ERROR))
        ->and([$storage->state('crm')?->version, $storage->table('role_grants')->count(), $storage->table('permission_grants')->count()])->toBe($before)
        ->and(is_file(app(CatalogCache::class)->path()))->toBeFalse();
});
