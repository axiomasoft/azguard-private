<?php

declare(strict_types=1);

use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Filament\Resources\RoleResource\RelationManagers\RoleUsersRelationManager;
use AzGuard\Models\DirectGrant;
use AzGuard\Tests\Stubs\User;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * F12: `az-guard-filament.user_label_column` (config/az-guard-filament.php:135) is the
 * only place this key is merged — reading it back as `az-guard.filament.user_label_column`
 * (an unregistered config tree) always silently falls back to the 'name' default.
 */
function buildDirectGrantTableForLabelTest(): Table
{
    /** @var HasTable $livewire */
    $livewire = Mockery::mock(HasTable::class)->shouldIgnoreMissing();

    return DirectGrantResource::table(Table::make($livewire));
}

function buildRoleUsersTable(): Table
{
    /** @var HasTable $livewire */
    $livewire = Mockery::mock(HasTable::class);

    /** @var RoleUsersRelationManager $manager */
    $manager = (new ReflectionClass(RoleUsersRelationManager::class))->newInstanceWithoutConstructor();

    return $manager->table(Table::make($livewire));
}

it('DirectGrantResource table renders the configured user_label_column, not the hardcoded default', function (): void {
    config(['az-guard-filament.user_label_column' => 'email']);

    $user = User::factory()->create(['name' => 'Ignored Name', 'email' => 'configured@example.test']);
    $grant = DirectGrant::query()->create([
        'grantable_type' => $user::class,
        'grantable_id' => $user->getKey(),
        'panel_id' => 'admin',
        'permission_key' => 'admin.project.view',
        'expires_at' => null,
    ]);

    $column = collect(buildDirectGrantTableForLabelTest()->getColumns())
        ->first(fn ($column): bool => $column->getName() === 'grantable_id');
    $column->record($grant);

    expect($column->formatState((string) $grant->grantable_id))->toBe('configured@example.test');
});

it('RoleUsersRelationManager table uses the configured user_label_column as record title', function (): void {
    config(['az-guard-filament.user_label_column' => 'email']);

    $table = buildRoleUsersTable();

    expect($table->getRecordTitleAttribute())->toBe('email')
        ->and(collect($table->getColumns())->map(fn ($column): string => $column->getName()))
        ->toContain('email');
});
