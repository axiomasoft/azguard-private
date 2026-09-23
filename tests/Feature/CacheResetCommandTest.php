<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Facades\AzGuard;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('guard:cache-reset advances revision and leaves a foreign store key intact', function (): void {
    config()->set('cache.stores.azguard_test', ['driver' => 'array']);
    config()->set('az-guard.cache.store', 'azguard_test');

    $user = User::factory()->create();
    AzGuard::forUser($user)->on('test')->grant('test.post.view');
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    Cache::store('azguard_test')->put('app:sentinel', 'keep-me', 600);
    $before = app(PermissionStateRevision::class)->current();

    $this->artisan('guard:cache-reset', ['--force' => true])
        ->expectsOutputToContain('permission cache reset')
        ->assertSuccessful();

    expect(Cache::store('azguard_test')->get('app:sentinel'))->toBe('keep-me')
        ->and(app(PermissionStateRevision::class)->current())->toBe($before + 1);

    app()->forgetScopedInstances();
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();
});

it('guard:cache-reset bumps revision even when the cache store is array', function (): void {
    config()->set('az-guard.cache.store', 'array');
    $before = app(PermissionStateRevision::class)->current();

    $this->artisan('guard:cache-reset', ['--force' => true])
        ->expectsOutputToContain('permission cache reset')
        ->assertSuccessful();

    expect(app(PermissionStateRevision::class)->current())->toBe($before + 1);
});

it('guard:cache-reset is nonzero and leaves revision unchanged when the bump fails', function (): void {
    $before = app(PermissionStateRevision::class)->current();
    DB::table(Config::permissionStateTable())->delete();

    $this->artisan('guard:cache-reset', ['--force' => true])
        ->expectsOutputToContain('Failed to advance permission-state revision')
        ->assertFailed();

    DB::table(Config::permissionStateTable())->insert([
        'id' => PermissionStateRevision::SINGLETON_ID,
        'revision' => $before,
    ]);

    expect(app(PermissionStateRevision::class)->current())->toBe($before);
});
