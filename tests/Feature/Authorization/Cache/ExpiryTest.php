<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\Cache\FencedCacheSource;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config(['azguard.cache.namespace' => 'test-expiry', 'azguard.cache.signing_key' => str_repeat('k', 32)]);
});

it('expires warmed ordinary and administrative authority at the exact absolute boundary', function (bool $admin): void {
    $expires = Carbon::now('UTC')->addSecond()->toDateTimeImmutable();
    $source = new FencedCacheSource;
    $source->reuse = Volatility::Stable;

    if ($admin) {
        $source->roles = [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated', expiresAt: $expires)];
    } else {
        $source->direct = [AuthorizationWorld::grant(expires: $expires)];
    }
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->cache('array'));
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();

    if ($admin) {
        expect($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBeTrue();
    }
    Carbon::setTestNow($expires);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);

    if ($admin) {
        expect($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBeFalse();
    }
    expect($source->revision)->toBe(1);
})->with([false, true]);

it('bounds cache validity by the nearest active expiry and rejects equality at now', function (): void {
    [$engine, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array', 60));
    $cache = app(PermissionSetCache::class);
    $now = Carbon::now()->toDateTimeImmutable();
    $nearest = $now->modify('+2 seconds');
    $expired = AuthorizationWorld::grant(expires: $now);
    $short = AuthorizationWorld::grant(expires: $nearest);
    $long = AuthorizationWorld::grant(expires: $now->modify('+30 seconds'));
    $cache->put('nearest-expiry', $panel, Volatility::Stable, true, [$expired, $short, $long], $now);
    $envelope = json_decode(app('cache')->store('array')->get('nearest-expiry'), true);
    $stored = unserialize(base64_decode($envelope['payloadBase64'], true));
    expect($stored['validUntil'])->toEqual($nearest)
        ->and($cache->get('nearest-expiry', $panel, Volatility::Stable, true, $now))->toBe([$short, $long])
        ->and($cache->get('nearest-expiry', $panel, Volatility::Stable, true, $nearest))->toBeNull();
});

it('enforces the configured absolute ttl even for nonexpiring raw authority', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array', 2));
    $cache = app(PermissionSetCache::class);
    $now = Carbon::now()->toDateTimeImmutable();
    $cache->put('ttl-expiry', $panel, Volatility::Request, false, [AuthorizationWorld::grant()], $now);
    expect($cache->get('ttl-expiry', $panel, Volatility::Request, false, $now->modify('+1 second')))->toHaveCount(1)
        ->and($cache->get('ttl-expiry', $panel, Volatility::Request, false, $now->modify('+2 seconds')))->toBeNull();
});

it('caps the shared lifetime even when a longer ttl is requested', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array', 7200));
    $now = Carbon::now()->toDateTimeImmutable();
    (new PermissionSetCache(app('cache')))->put('hard-cap', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
    $envelope = json_decode(app('cache')->store('array')->get('hard-cap'), true);
    $stored = unserialize(base64_decode($envelope['payloadBase64'], true));
    expect($stored['validUntil'])->toEqual($now->modify('+3600 seconds'));
    expect((new PermissionSetCache(app('cache')))->get('hard-cap', $panel, Volatility::Stable, true, $now->modify('+3600 seconds')))->toBeNull();
});
