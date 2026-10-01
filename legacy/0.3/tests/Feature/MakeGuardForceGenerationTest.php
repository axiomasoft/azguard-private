<?php

declare(strict_types=1);

use AzGuard\Tests\Stubs\TestGuardPanelProvider;
use Illuminate\Support\Facades\File;

function guardForceTestToken(): string
{
    $token = getenv('TEST_TOKEN');

    return is_string($token) && $token !== '' ? $token : (string) getmypid();
}

function guardForceTestPath(): string
{
    return 'app/Guards/P4Force'.guardForceTestToken();
}

/**
 * F33 — `--force` for every `make:guard-*` command, plus argument-driven
 * (non-interactive) `make:guard-role`. All commands share the
 * `SupportsForcefulGeneration` trait: existing files abort unless `--force`.
 */
beforeEach(function (): void {
    File::deleteDirectory(directory: base_path(guardForceTestPath()));

    // The `test` panel provider lives in tests/Stubs; make:guard-role derives
    // the target from the provider's own directory (Reflection), so generated
    // roles land in tests/Stubs/Roles. Track them for cleanup.
    $providerDir = dirname((new ReflectionClass(TestGuardPanelProvider::class))->getFileName());
    $this->generatedRolePaths = [
        $providerDir.'/Roles/ForcedEditor'.guardForceTestToken().'WithoutForceRole.php',
        $providerDir.'/Roles/ForcedEditor'.guardForceTestToken().'WithForceRole.php',
        $providerDir.'/Roles/ArgDriven'.guardForceTestToken().'Role.php',
    ];

    foreach ($this->generatedRolePaths as $path) {
        File::delete($path);
    }
});

afterEach(function (): void {
    File::deleteDirectory(directory: base_path(guardForceTestPath()));

    foreach ($this->generatedRolePaths ?? [] as $path) {
        File::delete($path);
    }
});

it('generates a role non-interactively from panel and name arguments', function (): void {
    $roleName = 'ArgDriven'.guardForceTestToken();

    $this->artisan(command: 'make:guard-role', parameters: [
        'panel' => 'test',
        'name' => $roleName,
    ])->assertSuccessful();

    $providerDir = dirname((new ReflectionClass(TestGuardPanelProvider::class))->getFileName());
    $rolePath = $providerDir.'/Roles/'.$roleName.'Role.php';

    expect($rolePath)->toBeFile();

    $content = File::get($rolePath);
    expect($content)->toContain('class '.$roleName.'Role extends BaseRole')
        ->and($content)->toContain('namespace AzGuard\Tests\Stubs\Roles;');
});

it('rejects an unregistered panel passed as an argument', function (): void {
    $this->artisan(command: 'make:guard-role', parameters: [
        'panel' => 'does-not-exist',
        'name' => 'ArgDriven'.guardForceTestToken(),
    ])->assertFailed();
});

it('refuses to overwrite an existing role without --force', function (): void {
    $roleName = 'ForcedEditor'.guardForceTestToken().'WithoutForce';
    $providerDir = dirname((new ReflectionClass(TestGuardPanelProvider::class))->getFileName());
    $rolePath = $providerDir.'/Roles/'.$roleName.'Role.php';

    File::ensureDirectoryExists(dirname($rolePath));
    File::put($rolePath, '<?php // sentinel');

    $this->artisan(command: 'make:guard-role', parameters: [
        'panel' => 'test',
        'name' => $roleName,
    ])->assertFailed();

    // Untouched: the original sentinel content is preserved.
    expect(File::get($rolePath))->toContain('// sentinel');
});

it('overwrites an existing role with --force', function (): void {
    $roleName = 'ForcedEditor'.guardForceTestToken().'WithForce';
    $providerDir = dirname((new ReflectionClass(TestGuardPanelProvider::class))->getFileName());
    $rolePath = $providerDir.'/Roles/'.$roleName.'Role.php';

    File::ensureDirectoryExists(dirname($rolePath));
    File::put($rolePath, '<?php // sentinel');

    $this->artisan(command: 'make:guard-role', parameters: [
        'panel' => 'test',
        'name' => $roleName,
        '--force' => true,
    ])->assertSuccessful();

    $content = File::get($rolePath);
    expect($content)->not->toContain('// sentinel')
        ->and($content)->toContain('class '.$roleName.'Role extends BaseRole');
});

it('refuses to overwrite an existing policy without --force but succeeds with it', function (): void {
    $path = guardForceTestPath();
    $policyPath = base_path($path.'/App/Documents/Policies/DocumentsPolicy.php');
    File::ensureDirectoryExists(dirname($policyPath));
    File::put($policyPath, '<?php // sentinel');

    $this->artisan(command: 'make:guard-policy', parameters: [
        'panel' => 'App', 'domain' => 'Documents', '--path' => $path,
    ])->assertFailed();

    expect(File::get($policyPath))->toContain('// sentinel');

    $this->artisan(command: 'make:guard-policy', parameters: [
        'panel' => 'App', 'domain' => 'Documents', '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    expect(File::get($policyPath))->not->toContain('// sentinel')
        ->and(File::get($policyPath))->toContain('class DocumentsPolicy');
});

it('refuses to overwrite an existing abilities DTO without --force but succeeds with it', function (): void {
    $path = guardForceTestPath();
    $abilitiesPath = base_path($path.'/App/Documents/Abilities/DocumentsAbilities.php');
    File::ensureDirectoryExists(dirname($abilitiesPath));
    File::put($abilitiesPath, '<?php // sentinel');

    $this->artisan(command: 'make:guard-abilities', parameters: [
        'panel' => 'App', 'domain' => 'Documents', '--path' => $path,
    ])->assertFailed();

    expect(File::get($abilitiesPath))->toContain('// sentinel');

    $this->artisan(command: 'make:guard-abilities', parameters: [
        'panel' => 'App', 'domain' => 'Documents', '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    expect(File::get($abilitiesPath))->not->toContain('// sentinel')
        ->and(File::get($abilitiesPath))->toContain('class DocumentsAbilities');
});
