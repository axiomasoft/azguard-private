<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function guardPanelTestPath(string $suffix): string
{
    return 'app/Guards/P4Parallel'.(getenv('TEST_TOKEN') ?: 'sequential').'/'.$suffix;
}

function guardPanelTestRoot(): string
{
    return base_path('app/Guards/P4Parallel'.(getenv('TEST_TOKEN') ?: 'sequential'));
}

beforeEach(function (): void {
    File::deleteDirectory(guardPanelTestRoot());
});

afterEach(function (): void {
    File::deleteDirectory(guardPanelTestRoot());
});

it('создаёт guard-панель с доменной структурой', function (): void {
    $path = guardPanelTestPath(suffix: 'Structure');

    $this->artisan(
        command: 'make:guard-panel',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Documents',
            '--role' => 'SuperAdmin',
            '--path' => $path,
        ],
    )->assertSuccessful();

    $basePath = base_path($path.'/Admin');

    expect($basePath.'/AdminGuardPanelProvider.php')->toBeFile()
        ->and($basePath.'/Roles/SuperAdminRole.php')->toBeFile()
        ->and($basePath.'/Documents/Permissions/DocumentsPermission.php')->toBeFile()
        ->and($basePath.'/Documents/Policies/DocumentsPolicy.php')->toBeFile();

    $policyContent = File::get(path: $basePath.'/Documents/Policies/DocumentsPolicy.php');
    expect($policyContent)->toContain('namespace App\Guards\P4Parallel'.(getenv('TEST_TOKEN') ?: 'sequential').'\Structure\Admin\Documents\Policies;')
        ->and($policyContent)->toContain('use AuthorizesPermission;')
        ->and($policyContent)->toContain('#[GuardPolicy');

    // The provider wires the generated permission enum into the panel, so
    // enum-declared role permissions resolve out of the box.
    $providerContent = File::get(path: $basePath.'/AdminGuardPanelProvider.php');
    expect($providerContent)->toContain('->permissionEnums([')
        ->and($providerContent)->toContain('DocumentsPermission::class');
});

it('создаёт Abilities при флаге --with-abilities', function (): void {
    $path = guardPanelTestPath(suffix: 'Abilities');

    $this->artisan(
        command: 'make:guard-panel',
        parameters: [
            'panel' => 'BlogAdmin',
            'domain' => 'Posts',
            '--path' => $path,
            '--with-abilities' => true,
        ],
    )->assertSuccessful();

    expect(base_path($path.'/BlogAdmin/Posts/Abilities/PostsAbilities.php'))->toBeFile();
});

it('auto-registers the generated panel provider in config/az-guard.php', function (): void {
    $path = guardPanelTestPath(suffix: 'Registration');
    $application = app();
    $originalConfigPath = $application->configPath();
    $isolatedConfigPath = base_path('storage/framework/testing/P4Parallel'.(getenv('TEST_TOKEN') ?: 'sequential').'/config');
    $application->useConfigPath($isolatedConfigPath);
    $configPath = config_path('az-guard.php');

    File::ensureDirectoryExists(dirname($configPath));
    File::put($configPath, "<?php\n\nreturn [\n    'panels' => [],\n];\n");

    try {
        $this->artisan(
            command: 'make:guard-panel',
            parameters: ['panel' => 'Admin', 'domain' => 'Documents', '--path' => $path],
        )->assertSuccessful();

        expect(File::get($configPath))
            ->toContain('App\Guards\P4Parallel'.(getenv('TEST_TOKEN') ?: 'sequential').'\Registration\Admin\AdminGuardPanelProvider::class');
    } finally {
        $application->useConfigPath($originalConfigPath);
        File::deleteDirectory($isolatedConfigPath);
    }
});

it('отказывается если панель уже существует', function (): void {
    $path = guardPanelTestPath(suffix: 'Existing');
    File::makeDirectory(path: base_path($path.'/ExistingPanel'), mode: 0755, recursive: true);

    $this->artisan(
        command: 'make:guard-panel',
        parameters: ['panel' => 'ExistingPanel', 'domain' => 'Docs', '--path' => $path],
    )
        ->assertFailed();
});
