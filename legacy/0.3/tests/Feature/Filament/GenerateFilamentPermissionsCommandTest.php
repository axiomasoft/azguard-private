<?php

declare(strict_types=1);

use AzGuard\Filament\Permissions\FilamentDiscovery;
use AzGuard\Filament\Permissions\PermissionDiscovery;
use AzGuard\Tests\Stubs\Project;
use Illuminate\Filesystem\Filesystem;

it('reports discovered keys for the database source', function (): void {
    $this->artisan('guard:filament:generate', ['--source' => 'database'])
        ->expectsOutputToContain('Permission schema for panel [admin]')
        ->expectsOutputToContain('admin.project.view_any')
        ->assertSuccessful();
});

it('dry-runs enum generation for model-backed subjects', function (): void {
    $this->artisan('guard:filament:generate', [
        '--source' => 'enum',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('would write:')
        ->expectsOutputToContain('Dry run complete.')
        ->assertSuccessful();
});

it('dry-runs policy generation for model-backed subjects', function (): void {
    $this->artisan('guard:filament:generate', [
        '--source' => 'policy',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('would write:')
        ->expectsOutputToContain('Dry run complete.')
        ->assertSuccessful();
});

it('writes enums when the destination directory is created', function (): void {
    $path = 'app/Guards/CoverageEnums'.(string) getmypid();
    config()->set('az-guard-filament.generation.enum_namespace', 'App\\Guards\\CoverageEnums');
    config()->set('az-guard-filament.generation.enum_path', $path);

    $this->artisan('guard:filament:generate', ['--source' => 'enum'])
        ->expectsOutputToContain('wrote:')
        ->assertSuccessful();

    expect(base_path($path.'/ProjectPermission.php'))->toBeFile();

    (new Filesystem)->deleteDirectory(base_path($path));
});

it('rejects an unknown source', function (): void {
    $this->artisan('guard:filament:generate', ['--source' => 'xml'])
        ->expectsOutputToContain('Unsupported source [xml]')
        ->assertExitCode(1);
});

it('warns when discovery finds nothing', function (): void {
    $this->app->singleton(
        PermissionDiscovery::class,
        fn (): PermissionDiscovery => new class implements PermissionDiscovery
        {
            public function subjects(string $panelId): array
            {
                return [];
            }
        },
    );

    $this->artisan('guard:filament:generate')
        ->expectsOutputToContain('No Filament resources or pages discovered')
        ->assertSuccessful();
});

it('returns no subjects when Filament has no matching AzGuard panel', function (): void {
    $discovery = new FilamentDiscovery(
        abilities: ['view'],
        exclude: ['resources' => [Project::class]],
    );

    expect($discovery->subjects('admin'))->toBe([]);
});
