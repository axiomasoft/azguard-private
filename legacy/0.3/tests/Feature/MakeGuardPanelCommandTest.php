<?php

declare(strict_types=1);

use AzGuard\Tests\Stubs\Document;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Support\Facades\File;

function guardPanelTestPath(string $suffix): string
{
    return 'app/Guards/P4Parallel'.guardPanelTestToken().'/'.$suffix;
}

function guardPanelTestToken(): string
{
    $token = getenv('TEST_TOKEN');

    return is_string($token) && $token !== '' ? $token : (string) getmypid();
}

it('создаёт guard-панель с доменной структурой', function (): void {
    $path = guardPanelTestPath(suffix: 'Structure');
    File::deleteDirectory(base_path($path));

    $this->artisan(
        command: 'make:guard-panel',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Documents',
            '--role' => 'SuperAdmin',
            '--path' => $path,
            '--model' => Document::class,
        ],
    )->assertSuccessful();

    $basePath = base_path($path.'/Admin');

    expect($basePath.'/AdminGuardPanelProvider.php')->toBeFile()
        ->and($basePath.'/Roles/SuperAdminRole.php')->toBeFile()
        ->and($basePath.'/Documents/Permissions/DocumentsPermission.php')->toBeFile()
        ->and($basePath.'/Documents/Policies/DocumentsPolicy.php')->toBeFile();

    $policyContent = File::get(path: $basePath.'/Documents/Policies/DocumentsPolicy.php');
    expect($policyContent)->toContain('namespace App\Guards\P4Parallel'.guardPanelTestToken().'\Structure\Admin\Documents\Policies;')
        ->and($policyContent)->toContain('use AuthorizesPermission;')
        ->and($policyContent)->toContain('#[GuardPolicy')
        ->and($policyContent)->toContain(Document::class);

    // The provider wires the generated permission enum into the panel, so
    // enum-declared role permissions resolve out of the box.
    $providerContent = File::get(path: $basePath.'/AdminGuardPanelProvider.php');
    expect($providerContent)->toContain('->permissionEnums([')
        ->and($providerContent)->toContain('DocumentsPermission::class');
});

it('создаёт Abilities при флаге --with-abilities', function (): void {
    $path = guardPanelTestPath(suffix: 'Abilities');
    File::deleteDirectory(base_path($path));

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
    File::deleteDirectory(base_path($path));
    $application = app();
    $originalConfigPath = $application->configPath();
    $isolatedConfigPath = base_path('storage/framework/testing/P4Parallel'.guardPanelTestToken().'/config');
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
            ->toContain('App\Guards\P4Parallel'.guardPanelTestToken().'\Registration\Admin\AdminGuardPanelProvider::class');
    } finally {
        $application->useConfigPath($originalConfigPath);
        File::deleteDirectory($isolatedConfigPath);
    }
});

it('отказывается если панель уже существует', function (): void {
    $path = guardPanelTestPath(suffix: 'Existing');
    File::deleteDirectory(base_path($path));
    File::makeDirectory(path: base_path($path.'/ExistingPanel'), mode: 0755, recursive: true);

    $this->artisan(
        command: 'make:guard-panel',
        parameters: ['panel' => 'ExistingPanel', 'domain' => 'Docs', '--path' => $path],
    )
        ->assertFailed();
});

it('validates model, actor, identifiers, and path before writing a panel', function (): void {
    $path = guardPanelTestPath(suffix: 'InvalidInputs');
    File::deleteDirectory(base_path($path));

    foreach ([
        ['panel' => '../Admin', 'domain' => 'Documents', '--path' => $path, '--model' => Document::class],
        ['panel' => 'Admin', 'domain' => 'Documents', '--path' => '../outside', '--model' => Document::class],
        ['panel' => 'Admin', 'domain' => 'Documents', '--path' => $path, '--model' => stdClass::class],
        ['panel' => 'Admin', 'domain' => 'Documents', '--path' => $path, '--model' => Document::class, '--actor' => stdClass::class],
    ] as $parameters) {
        $this->artisan(command: 'make:guard-panel', parameters: $parameters)->assertFailed();
    }

    expect(base_path($path.'/Admin'))->not->toBeDirectory();
});

it('keeps identical panel reruns byte-for-byte and forces only changed generated targets', function (): void {
    $path = guardPanelTestPath(suffix: 'Idempotent');
    File::deleteDirectory(base_path($path));
    $parameters = ['panel' => 'Admin', 'domain' => 'Documents', '--path' => $path, '--model' => Document::class];

    $this->artisan(command: 'make:guard-panel', parameters: $parameters)->assertSuccessful();
    $basePath = base_path($path.'/Admin');
    $policyPath = $basePath.'/Documents/Policies/DocumentsPolicy.php';
    $expected = File::get(path: $policyPath);
    $unrelated = $basePath.'/custom.php';
    File::put(path: $unrelated, contents: '<?php // owned by application');

    $this->artisan(command: 'make:guard-panel', parameters: $parameters)->assertSuccessful();
    expect(File::get(path: $policyPath))->toBe($expected);

    File::put(path: $policyPath, contents: '<?php // conflicting target');
    $this->artisan(command: 'make:guard-panel', parameters: $parameters)->assertFailed();
    expect(File::get(path: $policyPath))->toBe('<?php // conflicting target');

    $this->artisan(command: 'make:guard-panel', parameters: $parameters + ['--force' => true])->assertSuccessful();
    expect(File::get(path: $policyPath))->toBe($expected)
        ->and(File::get(path: $unrelated))->toBe('<?php // owned by application');
});

it('does not write panel files when config panels layout is custom', function (): void {
    $path = guardPanelTestPath(suffix: 'CustomConfig');
    File::deleteDirectory(base_path($path));
    $application = app();
    $originalConfigPath = $application->configPath();
    $isolatedConfigPath = base_path('storage/framework/testing/P5CustomConfig'.guardPanelTestToken().'/config');
    $application->useConfigPath($isolatedConfigPath);
    $configPath = config_path('az-guard.php');
    File::ensureDirectoryExists(path: dirname($configPath));
    $custom = "<?php\nreturn ['panels' => array_merge([], [])];\n";
    File::put(path: $configPath, contents: $custom);

    try {
        $this->artisan(command: 'make:guard-panel', parameters: [
            'panel' => 'Admin', 'domain' => 'Documents', '--path' => $path, '--model' => Document::class,
        ])->assertFailed();

        expect(base_path($path.'/Admin'))->not->toBeDirectory()
            ->and(File::get(path: $configPath))->toBe($custom);
    } finally {
        $application->useConfigPath($originalConfigPath);
        File::deleteDirectory(directory: $isolatedConfigPath);
    }
});

it('renders explicit Authenticatable actor and model FQCNs in policy', function (): void {
    $path = guardPanelTestPath(suffix: 'ExplicitActor');
    File::deleteDirectory(base_path($path));

    $this->artisan(command: 'make:guard-panel', parameters: [
        'panel' => 'Admin',
        'domain' => 'Documents',
        '--path' => $path,
        '--model' => Document::class,
        '--actor' => UserWithDirectGrants::class,
    ])->assertSuccessful();

    $policy = File::get(path: base_path($path.'/Admin/Documents/Policies/DocumentsPolicy.php'));
    expect($policy)->toContain('\\'.Document::class.'::class')
        ->toContain('\\'.UserWithDirectGrants::class.' $user');
});
