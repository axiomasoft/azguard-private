<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Context\ContextPermissionLayer;
use AzGuard\Context\Strategies\ContextOnlyStrategy;
use AzGuard\Context\Strategies\DenyWithoutContextStrategy;
use AzGuard\Context\Strategies\GlobalPlusContextStrategy;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Stubs\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->manager = app(AuthorizationContextManager::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    $this->manager->clearAll();
});

function seedContextPermission(User $user, string $type, int $id, string $key, ?string $expiresAt = null): void
{
    DB::table('az_guard_context_roles')->insert([
        'model_type' => User::class,
        'model_id' => $user->getAuthIdentifier(),
        'context_type' => $type,
        'context_id' => $id,
        'panel_id' => 'app',
        'permission_key' => $key,
        'expires_at' => $expiresAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('adds context permissions to the global set (GlobalPlusContext)', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 42));
    seedContextPermission($this->user, 'workspace', 42, 'app.posts.edit');

    $layer = new ContextPermissionLayer($this->manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::fromKeys(['app.dashboard.view']), $this->user, 'app');

    expect($result->grants('app.posts.edit'))->toBeTrue()
        ->and($result->grants('app.dashboard.view'))->toBeTrue();
});

it('keeps global permissions when no context is set (GlobalPlusContext)', function () {
    $layer = new ContextPermissionLayer($this->manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::fromKeys(['app.dashboard.view']), $this->user, 'app');

    expect($result->grants('app.dashboard.view'))->toBeTrue();
});

it('ContextOnly ignores global permissions entirely', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 42));
    seedContextPermission($this->user, 'workspace', 42, 'app.posts.edit');

    $layer = new ContextPermissionLayer($this->manager, new ContextOnlyStrategy);
    $result = $layer->apply(PermissionSet::fromKeys(['app.dashboard.view']), $this->user, 'app');

    expect($result->grants('app.posts.edit'))->toBeTrue()
        // The global permission is suppressed — context-only isolation.
        ->and($result->grants('app.dashboard.view'))->toBeFalse();
});

it('ContextOnly grants nothing when no context is set', function () {
    $layer = new ContextPermissionLayer($this->manager, new ContextOnlyStrategy);
    $result = $layer->apply(PermissionSet::fromKeys(['app.dashboard.view']), $this->user, 'app');

    expect($result->isEmpty())->toBeTrue();
});

it('DenyWithoutContext denies everything when no context is set', function () {
    $layer = new ContextPermissionLayer($this->manager, new DenyWithoutContextStrategy);
    $result = $layer->apply(PermissionSet::fromKeys(['app.dashboard.view']), $this->user, 'app');

    expect($result->isEmpty())->toBeTrue();
});

it('DenyWithoutContext allows global and context when a context is set', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 42));
    seedContextPermission($this->user, 'workspace', 42, 'app.posts.edit');

    $layer = new ContextPermissionLayer($this->manager, new DenyWithoutContextStrategy);
    $result = $layer->apply(PermissionSet::fromKeys(['app.dashboard.view']), $this->user, 'app');

    expect($result->grants('app.posts.edit'))->toBeTrue()
        ->and($result->grants('app.dashboard.view'))->toBeTrue();
});

it('does not return permissions for a different context id', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 99));
    seedContextPermission($this->user, 'workspace', 42, 'app.posts.edit');

    $layer = new ContextPermissionLayer($this->manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::empty(), $this->user, 'app');

    expect($result->grants('app.posts.edit'))->toBeFalse();
});

it('does not return permissions for a different context type', function () {
    $this->manager->set(new AuthorizationContext('app', 'project', 42));
    seedContextPermission($this->user, 'workspace', 42, 'app.posts.edit');

    $layer = new ContextPermissionLayer($this->manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::empty(), $this->user, 'app');

    expect($result->grants('app.posts.edit'))->toBeFalse();
});

it('a context wildcard grants everything', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 42));
    seedContextPermission($this->user, 'workspace', 42, '*');

    $layer = new ContextPermissionLayer($this->manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::empty(), $this->user, 'app');

    expect($result->grants('anything.at.all'))->toBeTrue();
});

it('propagates nearest context expires_at and drops expired rows', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 42));
    $early = now()->addHour();
    seedContextPermission($this->user, 'workspace', 42, 'app.posts.edit', $early->toDateTimeString());
    seedContextPermission($this->user, 'workspace', 42, 'app.posts.expired', now()->subSecond()->toDateTimeString());

    $layer = new ContextPermissionLayer($this->manager, new GlobalPlusContextStrategy);
    $result = $layer->apply(PermissionSet::empty(), $this->user, 'app');

    expect($result->grants('app.posts.edit'))->toBeTrue()
        ->and($result->grants('app.posts.expired'))->toBeFalse()
        ->and($result->validUntil())->not->toBeNull();
});
