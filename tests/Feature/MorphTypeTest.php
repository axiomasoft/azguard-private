<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\UlidUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('migrates morph keys as non-integer columns when morph_type is ulid', function (): void {
    expect(Schema::getColumnType(Config::modelHasRolesTable(), 'model_id'))->not->toBe('integer')
        ->and(Schema::getColumnType(Config::modelHasScopesTable(), 'model_id'))->not->toBe('integer')
        ->and(Schema::getColumnType(Config::directGrantsTable(), 'grantable_id'))->not->toBe('integer');
});

it('assigns a role to a ULID-keyed model', function (): void {
    $role = Role::create(['name' => 'editor']);

    $user = UlidUser::create([
        'name' => 'Ulid User',
        'email' => 'ulid@example.com',
        'password' => 'secret',
    ]);

    expect(Str::isUlid($user->id))->toBeTrue();

    $user->assignRole($role);

    expect($user->hasRole('editor'))->toBeTrue()
        ->and(
            DB::table(Config::modelHasRolesTable())
                ->where('model_id', $user->id)
                ->where('role_id', $role->id)
                ->exists(),
        )->toBeTrue();
});
