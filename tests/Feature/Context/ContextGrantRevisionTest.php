<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Context\ContextGrantBuilder;
use AzGuard\Context\Models\ContextRole;
use AzGuard\Facades\AzGuard;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\DB;

it('bumps revision on a context grant and leaves it unchanged on a no-op retry', function (): void {
    $user = User::factory()->create();
    $before = app(PermissionStateRevision::class)->current();

    AzGuard::forUser($user)->on('test')->inContext('workspace', 42)->grant('test.post.view');

    $after = app(PermissionStateRevision::class)->current();

    expect($after)->toBe($before + 1)
        ->and(ContextRole::query()->count())->toBe(1);

    AzGuard::forUser($user)->on('test')->inContext('workspace', 42)->grant('test.post.view');

    expect(app(PermissionStateRevision::class)->current())->toBe($after)
        ->and(ContextRole::query()->count())->toBe(1);

    AzGuard::forUser($user)->on('test')->inContext('workspace', 42)->revoke('test.post.view');

    expect(app(PermissionStateRevision::class)->current())->toBe($after + 1)
        ->and(ContextRole::query()->count())->toBe(0);
});

it('rolls a context grant back when revision write fails', function (): void {
    $user = User::factory()->create();

    DB::table(Config::permissionStateTable())->delete();

    expect(fn () => (new ContextGrantBuilder($user))->on('test')->inContext('workspace', 9)->grant('test.post.view'))
        ->toThrow(RuntimeException::class, 'AzGuard permission-state row is missing.');

    expect(ContextRole::query()->count())->toBe(0);
});

it('keeps raw context model writes and their revision in one transaction', function (): void {
    $user = User::factory()->create();
    $attributes = [
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getAuthIdentifier(),
        'context_type' => 'workspace',
        'context_id' => '9',
        'panel_id' => 'test',
        'permission_key' => 'test.post.view',
    ];
    $before = app(PermissionStateRevision::class)->current();

    $grant = ContextRole::query()->create($attributes);
    expect(app(PermissionStateRevision::class)->current())->toBe($before + 1);

    $grant->save();
    expect(app(PermissionStateRevision::class)->current())->toBe($before + 1);

    DB::table(Config::permissionStateTable())->delete();

    expect(fn () => $grant->delete())
        ->toThrow(RuntimeException::class, 'AzGuard permission-state row is missing.');
    expect(ContextRole::query()->whereKey($grant->getKey())->exists())->toBeTrue();
});
