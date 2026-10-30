<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Authorization\Internal\Cache\SignedEnvelope;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\Internal\Checks\CacheStore;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\Cache\FencedCacheSource;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    config(['azguard.cache.namespace' => 'test-app', 'azguard.cache.signing_key' => str_repeat('a', 32), 'azguard.cache.key_id' => 'old', 'azguard.cache.previous_keys' => []]);
});

it('rejects tampering, unsigned entries, copies and malformed envelopes before deserialization', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array', 60));
    $now = Carbon::now('UTC')->toDateTimeImmutable();
    $cache = new PermissionSetCache(app('cache'));
    $store = app('cache')->store('array');
    $cache->put('one', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
    $original = $store->get('one');
    expect((new PermissionSetCache(app('cache')))->get('one', $panel, Volatility::Stable, true, $now))->toHaveCount(1);
    $entry = json_decode($original, true);
    $variants = [serialize(['items' => [AuthorizationWorld::grant()], 'validUntil' => $now->modify('+60 seconds')]), substr($original, 0, -2), str_repeat('x', SignedEnvelope::MAX_BYTES + 1), str_replace('{"v":1,', '{"v":1,"v":1,', $original)];
    foreach (['v' => 2, 'kid' => 'unknown', 'namespace' => 'other', 'keyDigest' => str_repeat('0', 64), 'payloadBase64' => base64_encode(serialize(new EnvelopeWakeupSentinel)), 'macHex' => str_repeat('0', 64), 'issuedAtUTC' => [], 'extra' => 'unexpected'] as $field => $value) {
        $modified = $entry;
        $modified[$field] = $value;
        $variants[] = json_encode($modified);
    }
    foreach ($variants as $value) {
        $store->put('one', $value, 60);
        expect((new PermissionSetCache(app('cache')))->get('one', $panel, Volatility::Stable, true, $now))->toBeNull()
            ->and($store->get('one'))->toBeNull();
    }
    expect(EnvelopeWakeupSentinel::$woken)->toBeFalse();
    $store->put('two', $original, 60);
    expect((new PermissionSetCache(app('cache')))->get('two', $panel, Volatility::Stable, true, $now))->toBeNull()
        ->and($store->get('two'))->toBeNull();
    config(['azguard.cache.namespace' => 'other-app']);
    $store->put('one', $original, 60);
    expect((new PermissionSetCache(app('cache')))->get('one', $panel, Volatility::Stable, true, $now))->toBeNull();
});

it('rotates keys only within the explicitly bounded verification window', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array', 60));
    $now = Carbon::now('UTC')->toDateTimeImmutable();
    (new PermissionSetCache(app('cache')))->put('rotation', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
    $raw = app('cache')->store('array')->get('rotation');
    config(['azguard.cache.key_id' => 'new', 'azguard.cache.signing_key' => str_repeat('b', 32), 'azguard.cache.previous_keys' => ['old' => ['key' => str_repeat('a', 32), 'valid_until' => $now->modify('+30 seconds')->format('Y-m-d\TH:i:s.u\Z')]]]);
    expect((new PermissionSetCache(app('cache')))->get('rotation', $panel, Volatility::Stable, true, $now))->toHaveCount(1)
        ->and((new PermissionSetCache(app('cache')))->get('rotation', $panel, Volatility::Stable, true, $now->modify('+30 seconds')))->toBeNull();
    app('cache')->store('array')->put('rotation', $raw, 60);
    config(['azguard.cache.previous_keys' => []]);
    expect((new PermissionSetCache(app('cache')))->get('rotation', $panel, Volatility::Stable, true, $now))->toBeNull();
});

it('bypasses the shared store when signing configuration is missing', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array', 60));
    config(['azguard.cache.namespace' => null]);
    $now = Carbon::now()->toDateTimeImmutable();
    $cache = new PermissionSetCache(app('cache'));
    $cache->put('unsigned', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
    expect(app('cache')->store('array')->get('unsigned'))->toBeNull()
        ->and($cache->get('unsigned', $panel, Volatility::Stable, true, $now))->toHaveCount(1)
        ->and((new PermissionSetCache(app('cache')))->get('unsigned', $panel, Volatility::Stable, true, $now))->toBeNull();
    $context = new DoctorContext([$panel], [], true, AzGuardConfig::fromRepository(app('config')));
    expect(array_map(static fn ($finding) => $finding->key, iterator_to_array((new CacheStore)->run($context))))->toContain('cache.signing');
});

it('reloads authoritative denial when an attacker writes an unsigned grant', function (): void {
    $source = new FencedCacheSource;
    $source->reuse = Volatility::Stable;
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->cache('array', 60));
    expect($engine->decide($panel, $request)->allowed())->toBeFalse();
    $store = app('cache')->store('array');
    $storage = (new ReflectionProperty($store->getStore(), 'storage'))->getValue($store->getStore());
    $keys = array_values(array_filter(array_keys($storage), static fn (string $key): bool => str_starts_with($key, 'azguard:sets:v2:')));
    expect($keys)->toHaveCount(1);
    $store->put($keys[0], serialize(['items' => [AuthorizationWorld::grant()], 'validUntil' => Carbon::now()->addMinute()->toDateTimeImmutable()]), 60);
    app()->forgetScopedInstances();
    expect($engine->decide($panel, $request)->allowed())->toBeFalse()->and($source->grantReads)->toBe(2);
});

it('keeps oversized authoritative data locally without writing it to the shared store', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array', 60));
    $now = Carbon::now()->toDateTimeImmutable();
    $grant = AuthorizationWorld::grant(fields: ['large' => str_repeat('x', SignedEnvelope::MAX_BYTES)]);
    $cache = new PermissionSetCache(app('cache'));
    $cache->put('large', $panel, Volatility::Stable, true, [$grant], $now);
    expect(app('cache')->store('array')->get('large'))->toBeNull()
        ->and($cache->get('large', $panel, Volatility::Stable, true, $now))->toEqual([$grant]);
});

final class EnvelopeWakeupSentinel
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
