<?php

declare(strict_types=1);

use AzGuard\Commands\CacheResetCommand;
use AzGuard\Commands\CatalogListCommand;
use AzGuard\Commands\CatalogValidateCommand;
use AzGuard\Commands\DoctorCommand;
use AzGuard\Commands\GrantCommand;
use AzGuard\Commands\GrantsListCommand;
use AzGuard\Commands\InstallCommand;
use AzGuard\Commands\ListPermissionsCommand;
use AzGuard\Commands\ListScopedRolesCommand;
use AzGuard\Commands\MakeGuardAbilitiesCommand;
use AzGuard\Commands\MakeGuardPanelCommand;
use AzGuard\Commands\MakeGuardPermissionCommand;
use AzGuard\Commands\MakeGuardPolicyCommand;
use AzGuard\Commands\MakeGuardRoleCommand;
use AzGuard\Commands\PruneGrantsCommand;
use AzGuard\Commands\RevokeGrantCommand;
use AzGuard\Commands\RolePermissionsCommand;
use AzGuard\Commands\SuperAdminCommand;
use AzGuard\Commands\SyncRolesCommand;
use Illuminate\Console\Command;

/**
 * Runtime commands all live under `guard:`; scaffolding generators (the ones
 * that write new files into the consuming app) live under `make:guard-`. No
 * other prefix should exist anywhere in the package's registered commands.
 *
 * @return array<class-string<Command>>
 */
function guardRuntimeCommandClasses(): array
{
    return [
        InstallCommand::class,
        DoctorCommand::class,
        CatalogListCommand::class,
        CatalogValidateCommand::class,
        RolePermissionsCommand::class,
        ListPermissionsCommand::class,
        ListScopedRolesCommand::class,
        GrantsListCommand::class,
        SyncRolesCommand::class,
        CacheResetCommand::class,
        GrantCommand::class,
        RevokeGrantCommand::class,
        PruneGrantsCommand::class,
        SuperAdminCommand::class,
    ];
}

/**
 * @return array<class-string<Command>>
 */
function guardMakeCommandClasses(): array
{
    return [
        MakeGuardPanelCommand::class,
        MakeGuardPermissionCommand::class,
        MakeGuardPolicyCommand::class,
        MakeGuardAbilitiesCommand::class,
        MakeGuardRoleCommand::class,
    ];
}

it('registers every runtime command under the guard: prefix', function () {
    foreach (guardRuntimeCommandClasses() as $class) {
        $signature = (new $class)->getName();

        expect($signature)->toStartWith('guard:');
    }
});

it('registers every scaffolding generator under the make:guard- prefix', function () {
    foreach (guardMakeCommandClasses() as $class) {
        $signature = (new $class)->getName();

        expect($signature)->toStartWith('make:guard-');
    }
});

it('does not register any command outside the guard:/make:guard- prefixes', function () {
    $allClasses = [...guardRuntimeCommandClasses(), ...guardMakeCommandClasses()];

    foreach ($allClasses as $class) {
        $signature = (string) (new $class)->getName();

        $isRuntime = str_starts_with($signature, 'guard:');
        $isGenerator = str_starts_with($signature, 'make:guard-');

        expect($isRuntime || $isGenerator)->toBeTrue("Unexpected command prefix on {$class}: {$signature}");
    }
});

it('has no dead self-referential aliases on any guard command', function () {
    foreach ([...guardRuntimeCommandClasses(), ...guardMakeCommandClasses()] as $class) {
        $command = new $class;
        $signature = (string) $command->getName();

        // F51: self-referential dead $aliases were removed — a command must
        // not alias its own primary signature.
        expect($command->getAliases())->not->toContain($signature);
    }
});

it('registers every command class in the service provider console bindings', function () {
    $providerSource = file_get_contents(
        __DIR__.'/../../packages/core/src/AzGuardServiceProvider.php',
    );

    expect($providerSource)->not->toBeFalse();

    foreach ([...guardRuntimeCommandClasses(), ...guardMakeCommandClasses()] as $class) {
        $shortName = substr((string) strrchr($class, '\\'), 1);

        expect($providerSource)->toContain("{$shortName}::class");
    }
});
