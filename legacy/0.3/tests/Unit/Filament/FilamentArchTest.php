<?php

declare(strict_types=1);
use Filament\Contracts\Plugin;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\ServiceProvider;

arch('filament plugin implements Filament Plugin contract')
    ->expect('AzGuard\\Filament\\AzGuardPlugin')
    ->toImplement(Plugin::class);

arch('filament service provider extends ServiceProvider')
    ->expect('AzGuard\\Filament\\AzGuardFilamentServiceProvider')
    ->toExtend(ServiceProvider::class);

arch('no debugging calls in filament package')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed()
    ->ignoring('AzGuard\\Tests');

arch('filament resources extend Filament Resource')
    ->expect('AzGuard\\Filament\\Resources')
    ->toExtend(Filament\Resources\Resource::class)
    ->ignoring([
        'AzGuard\\Filament\\Resources\\RoleResource\\Pages',
        'AzGuard\\Filament\\Resources\\RoleResource\\RelationManagers',
        'AzGuard\\Filament\\Resources\\DirectGrantResource\\Pages',
    ]);

arch('filament relation managers extend RelationManager')
    ->expect('AzGuard\\Filament\\Resources\\RoleResource\\RelationManagers')
    ->toExtend(RelationManager::class);

/**
 * Filament package architecture: enforce immutability on service classes.
 * Matrix (parametrized by namespace arrays):
 *  - permissions codegen (PermissionEnumGenerator, FilamentPermissionCatalogBuilder, etc.)
 *  - artisan commands (GenerateFilamentPermissionsCommand)
 *
 * Exception policy: generator base classes (PermissionSchema, PolicyGenerator, PermissionDiscovery)
 * are allowed to be non-final for internal extension, but concrete generators must be final.
 *
 * @see https://pestphp.com/docs/arch-testing — architecture testing with Pest
 */
arch('filament permissions codegen classes are final')
    ->expect('AzGuard\\Filament\\Permissions')
    ->toBeFinal()
    ->ignoring([
        'AzGuard\\Filament\\Permissions\\PermissionSchema',
        'AzGuard\\Filament\\Permissions\\PermissionDiscovery',
        'AzGuard\\Filament\\Permissions\\PolicyGenerator',
    ]);

arch('filament artisan commands are final')
    ->expect('AzGuard\\Filament\\Commands')
    ->toBeFinal();
