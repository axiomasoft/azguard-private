<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Tests\Stubs\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->manager = app(AuthorizationContextManager::class);
    $this->manager->clearAll();
});

it('hasPermissionIn() returns false when the user has no context permissions', function (): void {
    $user = User::factory()->create();

    expect($user->hasPermissionIn('workspace', 42, 'app.posts.edit', 'app'))->toBeFalse();
});

it('hasPermissionIn() returns true when a permission exists in the context table', function (): void {
    $user = User::factory()->create();

    DB::table('az_guard_context_roles')->insert([
        'model_type' => User::class,
        'model_id' => $user->getAuthIdentifier(),
        'context_type' => 'workspace',
        'context_id' => 42,
        'panel_id' => 'app',
        'permission_key' => 'app.posts.edit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($user->hasPermissionIn('workspace', 42, 'app.posts.edit', 'app'))->toBeTrue();
});

it('restores the previous global context after an isolated check', function (): void {
    $user = User::factory()->create();
    $this->manager->set(new AuthorizationContext('app', 'project', 99));

    $user->hasPermissionIn('workspace', 42, 'app.posts.edit', 'app');

    $restored = $this->manager->current('app');

    expect($restored)->not->toBeNull()
        ->and($restored->contextType)->toBe('project')
        ->and($restored->contextId)->toBe(99);
});

it('leaves the context cleared when none existed before the check', function (): void {
    $user = User::factory()->create();

    expect($this->manager->has('app'))->toBeFalse();

    $user->hasPermissionIn('workspace', 42, 'app.posts.edit', 'app');

    expect($this->manager->has('app'))->toBeFalse();
});

it('accepts a typed PermissionContext via hasPermission()', function (): void {
    $user = User::factory()->create();
    $context = new AuthorizationContext('app', 'workspace', 42);

    expect($user->hasPermission('app.posts.edit', 'app', $context))->toBeFalse();
});
