<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Database\Schema\MorphColumns;
use AzGuard\Models\Role;
use AzGuard\Tests\Stubs\UlidUser;
use Illuminate\Database\Schema\Blueprint;
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

it('creates the scopes unique index when the morph type is uuid', function (): void {
    $tables = [
        'roles' => 'uuid_morph_test_roles',
        'model_has_roles' => 'uuid_morph_test_model_has_roles',
        'model_has_scopes' => 'uuid_morph_test_model_has_scopes',
    ];
    $originalMorphType = config('az-guard.column_names.morph_type');
    $originalTableNames = config('az-guard.table_names');

    config()->set('az-guard.column_names.morph_type', 'uuid');

    foreach ($tables as $key => $table) {
        config()->set("az-guard.table_names.{$key}", $table);
    }

    try {
        Schema::create($tables['roles'], function (Blueprint $table): void {
            $table->id();
        });
        Schema::create($tables['model_has_roles'], function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            MorphColumns::add($table, 'model');
        });
        Schema::create($tables['model_has_scopes'], function (Blueprint $table): void {
            $table->id();
            MorphColumns::add($table, 'model');
            MorphColumns::add($table, 'scope_entity', nullable: true);
            $table->string('scope_class')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('panel_id')->nullable();
            $table->timestamps();
        });

        $migration = require dirname(__DIR__, 2)
            .'/packages/core/database/migrations/2026_01_01_000005_add_unique_constraints_to_model_has_roles_and_scopes.php';

        expect(fn (): mixed => DB::transaction(fn (): mixed => $migration->up()))
            ->not->toThrow(Throwable::class);
    } finally {
        Schema::dropIfExists($tables['model_has_scopes']);
        Schema::dropIfExists($tables['model_has_roles']);
        Schema::dropIfExists($tables['roles']);
        config()->set('az-guard.column_names.morph_type', $originalMorphType);
        config()->set('az-guard.table_names', $originalTableNames);
    }
});
