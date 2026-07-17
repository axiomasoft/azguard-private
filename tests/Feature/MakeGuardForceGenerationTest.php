<?php

declare(strict_types=1);

use AzGuard\Tests\Stubs\TestGuardPanelProvider;
use Illuminate\Support\Facades\File;

/**
 * F33 — `--force` for every `make:guard-*` command, plus argument-driven
 * (non-interactive) `make:guard-role`. All commands share the
 * `SupportsForcefulGeneration` trait: existing files abort unless `--force`.
 */
beforeEach(function (): void {
    File::deleteDirectory(directory: base_path('app/Guards'));

    // The `test` panel provider lives in tests/Stubs; make:guard-role derives
    // the target from the provider's own directory (Reflection), so generated
    // roles land in tests/Stubs/Roles. Track them for cleanup.
    $providerDir = dirname((new ReflectionClass(TestGuardPanelProvider::class))->getFileName());
    $this->generatedRolePaths = [
        $providerDir.'/Roles/ForcedEditorRole.php',
        $providerDir.'/Roles/ArgDrivenRole.php',
    ];

    foreach ($this->generatedRolePaths as $path) {
        File::delete($path);
    }
});

afterEach(function (): void {
    File::deleteDirectory(directory: base_path('app/Guards'));

    foreach ($this->generatedRolePaths ?? [] as $path) {
        File::delete($path);
    }
});

it('generates a role non-interactively from panel and name arguments', function (): void {
    $this->artisan(command: 'make:guard-role', parameters: [
        'panel' => 'test',
        'name' => 'ArgDriven',
    ])->assertSuccessful();

    $providerDir = dirname((new ReflectionClass(TestGuardPanelProvider::class))->getFileName());
    $rolePath = $providerDir.'/Roles/ArgDrivenRole.php';

    expect($rolePath)->toBeFile();

    $content = File::get($rolePath);
    expect($content)->toContain('class ArgDrivenRole extends BaseRole')
        ->and($content)->toContain('namespace AzGuard\Tests\Stubs\Roles;');
});

it('rejects an unregistered panel passed as an argument', function (): void {
    $this->artisan(command: 'make:guard-role', parameters: [
        'panel' => 'does-not-exist',
        'name' => 'ArgDriven',
    ])->assertFailed();
});

it('refuses to overwrite an existing role without --force', function (): void {
    $providerDir = dirname((new ReflectionClass(TestGuardPanelProvider::class))->getFileName());
    $rolePath = $providerDir.'/Roles/ForcedEditorRole.php';

    File::ensureDirectoryExists(dirname($rolePath));
    File::put($rolePath, '<?php // sentinel');

    $this->artisan(command: 'make:guard-role', parameters: [
        'panel' => 'test',
        'name' => 'ForcedEditor',
    ])->assertFailed();

    // Untouched: the original sentinel content is preserved.
    expect(File::get($rolePath))->toContain('// sentinel');
});

it('overwrites an existing role with --force', function (): void {
    $providerDir = dirname((new ReflectionClass(TestGuardPanelProvider::class))->getFileName());
    $rolePath = $providerDir.'/Roles/ForcedEditorRole.php';

    File::ensureDirectoryExists(dirname($rolePath));
    File::put($rolePath, '<?php // sentinel');

    $this->artisan(command: 'make:guard-role', parameters: [
        'panel' => 'test',
        'name' => 'ForcedEditor',
        '--force' => true,
    ])->assertSuccessful();

    $content = File::get($rolePath);
    expect($content)->not->toContain('// sentinel')
        ->and($content)->toContain('class ForcedEditorRole extends BaseRole');
});

it('refuses to overwrite an existing policy without --force but succeeds with it', function (): void {
    $policyPath = base_path('app/Guards/App/Documents/Policies/DocumentsPolicy.php');
    File::ensureDirectoryExists(dirname($policyPath));
    File::put($policyPath, '<?php // sentinel');

    $this->artisan(command: 'make:guard-policy', parameters: [
        'panel' => 'App',
        'domain' => 'Documents',
    ])->assertFailed();

    expect(File::get($policyPath))->toContain('// sentinel');

    $this->artisan(command: 'make:guard-policy', parameters: [
        'panel' => 'App',
        'domain' => 'Documents',
        '--force' => true,
    ])->assertSuccessful();

    expect(File::get($policyPath))->not->toContain('// sentinel')
        ->and(File::get($policyPath))->toContain('class DocumentsPolicy');
});

it('refuses to overwrite an existing abilities DTO without --force but succeeds with it', function (): void {
    $abilitiesPath = base_path('app/Guards/App/Documents/Abilities/DocumentsAbilities.php');
    File::ensureDirectoryExists(dirname($abilitiesPath));
    File::put($abilitiesPath, '<?php // sentinel');

    $this->artisan(command: 'make:guard-abilities', parameters: [
        'panel' => 'App',
        'domain' => 'Documents',
    ])->assertFailed();

    expect(File::get($abilitiesPath))->toContain('// sentinel');

    $this->artisan(command: 'make:guard-abilities', parameters: [
        'panel' => 'App',
        'domain' => 'Documents',
        '--force' => true,
    ])->assertSuccessful();

    expect(File::get($abilitiesPath))->not->toContain('// sentinel')
        ->and(File::get($abilitiesPath))->toContain('class DocumentsAbilities');
});
