<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Context\ContextPermissionLayer;
use AzGuard\Context\Strategies\GlobalPlusContextStrategy;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Support\Schema\MorphColumns;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * F26 — the context-roles table name is read from the context config
 * (`az-guard-context.table_names.context_roles`), NOT from a core key.
 *
 * These tests prove the ContextPermissionLayer honours the configured
 * table name: seeding rows only into a custom-named table (with the
 * default table absent) must still surface context permissions.
 */
beforeEach(function () {
    $this->manager = app(AuthorizationContextManager::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    $this->manager->clearAll();
});

function createContextRolesTable(string $name): void
{
    Schema::create($name, function (Blueprint $table): void {
        $table->id();
        MorphColumns::add($table, 'model');
        $table->string('context_type');
        $table->string('context_id');
        $table->string('panel_id');
        $table->string('permission_key');
        $table->timestamps();
    });
}

it('exposes the context-roles table name via the context config', function () {
    expect(config('az-guard-context.table_names.context_roles'))
        ->toBe('az_guard_context_roles');
});

it('reads context permissions from the table named in the context config', function () {
    $custom = 'tenant_context_roles';
    createContextRolesTable($custom);

    // Point the reader at the custom table via the context-config key.
    config()->set('az-guard-context.table_names.context_roles', $custom);

    // Seed ONLY the custom table; the default table stays empty.
    DB::table($custom)->insert([
        'model_type' => User::class,
        'model_id' => $this->user->getAuthIdentifier(),
        'context_type' => 'workspace',
        'context_id' => 42,
        'panel_id' => 'app',
        'permission_key' => 'app.posts.edit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->manager->set(new AuthorizationContext('app', 'workspace', 42));

    $layer = new ContextPermissionLayer($this->manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::empty(), $this->user, 'app');

    expect($result->grants('app.posts.edit'))->toBeTrue();
});

it('does not read from the default table when the config points elsewhere', function () {
    $custom = 'tenant_context_roles';
    createContextRolesTable($custom);

    // Seed the DEFAULT table only, then repoint the config at the empty custom table.
    DB::table('az_guard_context_roles')->insert([
        'model_type' => User::class,
        'model_id' => $this->user->getAuthIdentifier(),
        'context_type' => 'workspace',
        'context_id' => 42,
        'panel_id' => 'app',
        'permission_key' => 'app.posts.edit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config()->set('az-guard-context.table_names.context_roles', $custom);

    $this->manager->set(new AuthorizationContext('app', 'workspace', 42));

    $layer = new ContextPermissionLayer($this->manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::empty(), $this->user, 'app');

    // The reader must ignore the default table once the config is overridden.
    expect($result->grants('app.posts.edit'))->toBeFalse();
});
