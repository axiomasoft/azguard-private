<?php

declare(strict_types=1);

use AzGuard\Tests\ContextTestCase;
use AzGuard\Tests\Stubs\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(ContextTestCase::class, RefreshDatabase::class);

it('keeps case-distinct RBAC keys separate', function () {
    $user = User::factory()->create();
    $timestamps = ['created_at' => now(), 'updated_at' => now()];

    DB::table(config('az-guard.table_names.direct_grants'))->insert([
        [...$timestamps, 'grantable_type' => $user->getMorphClass(), 'grantable_id' => $user->getKey(), 'panel_id' => 'app', 'permission_key' => 'App.Reports.View', 'expires_at' => null],
        [...$timestamps, 'grantable_type' => $user->getMorphClass(), 'grantable_id' => $user->getKey(), 'panel_id' => 'app', 'permission_key' => 'app.reports.view', 'expires_at' => null],
    ]);

    DB::table('az_guard_context_roles')->insert([
        [...$timestamps, 'model_type' => $user->getMorphClass(), 'model_id' => $user->getKey(), 'context_type' => 'Workspace', 'context_id' => '42', 'panel_id' => 'app', 'permission_key' => 'app.reports.view'],
        [...$timestamps, 'model_type' => $user->getMorphClass(), 'model_id' => $user->getKey(), 'context_type' => 'workspace', 'context_id' => '42', 'panel_id' => 'app', 'permission_key' => 'app.reports.view'],
    ]);

    expect(DB::table(config('az-guard.table_names.direct_grants'))
        ->where('grantable_id', $user->getKey())
        ->pluck('permission_key')
        ->all())->toEqualCanonicalizing(['App.Reports.View', 'app.reports.view'])
        ->and(DB::table('az_guard_context_roles')
            ->where('model_id', $user->getKey())
            ->pluck('context_type')
            ->all())->toEqualCanonicalizing(['Workspace', 'workspace']);

    if (DB::getDriverName() !== 'mysql') {
        return;
    }

    $collations = DB::table('information_schema.columns')
        ->where('table_schema', DB::getDatabaseName())
        ->whereIn('table_name', [config('az-guard.table_names.direct_grants'), 'az_guard_context_roles'])
        ->whereIn('column_name', ['grantable_type', 'model_type', 'context_type', 'context_id', 'panel_id', 'permission_key'])
        ->pluck('COLLATION_NAME')
        ->all();

    expect($collations)->each->toBe('utf8mb4_bin');
});
