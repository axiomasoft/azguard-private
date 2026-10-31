<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Authorization\Internal\Cache\SignedEnvelope;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\Internal\Checks\CacheIntegrity;
use AzGuard\Kernel\Decision\Grant;
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
    // Array storage is local; the signing diagnostic applies to a configured shared Redis transport.
    [, $sharedPanel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('redis', 60));
    $context = new DoctorContext([$sharedPanel], [], true, AzGuardConfig::fromRepository(app('config')));
    expect(array_map(static fn ($finding) => $finding->key, iterator_to_array((new CacheIntegrity(app('config')))->run($context))))->toContain('cache.signing');
});

it('rehydrates signed contributions into detached factory values', function (string $class): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array', 60));
    $now = Carbon::now('UTC')->toDateTimeImmutable();
    $marker = 'original';
    $items = [];
    for ($i = 0; $i < 2; $i++) {
        // A signed serialized payload can carry references that the normal factories detach.
        $item = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $values = ['source' => 'generated', 'role' => RoleKey::of('admin', 'editor'),
            'scope' => AccessScope::in(TenantRef::global()), 'origin' => 'manual', 'expiresAt' => null,
            'fields' => ['marker' => &$marker]];

        if ($class === Grant::class) {
            $values['pattern'] = AuthorizationWorld::grant()->pattern;
        }
        foreach ($values as $name => $value) {
            (new ReflectionProperty($class, $name))->setValue($item, $value);
        }
        $items[] = $item;
    }
    $until = $now->modify('+60 seconds');
    $raw = SignedEnvelope::fromConfig(app('config'))->encode('detached', serialize(['items' => $items, 'validUntil' => $until]), $now, $until);
    app('cache')->store('array')->put('detached', $raw, 60);
    $cache = new PermissionSetCache(app('cache'));
    $read = $cache->get('detached', $panel, Volatility::Stable, true, $now);
    expect($read)->toHaveCount(2);
    $fields = $read[0]->fields();
    $fields['marker'] = 'mutated';
    expect($read[0]->fields()['marker'])->toBe('original')->and($read[1]->fields()['marker'])->toBe('original')
        ->and($cache->get('detached', $panel, Volatility::Stable, true, $now)[0]->fields()['marker'])->toBe('original');
})->with([Grant::class, RoleContribution::class]);

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

it('does not deserialize a forged outer value in a serialized array store', function (): void {
    config(['cache.stores.envelope_serialized' => ['driver' => 'array', 'serialize' => true], 'cache.serializable_classes' => null]);
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('envelope_serialized', 60));
    $now = Carbon::now()->toDateTimeImmutable();
    $store = app('cache')->store('envelope_serialized')->getStore();
    $cache = new PermissionSetCache(app('cache'));
    $cache->put('array-outer', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
    expect((new PermissionSetCache(app('cache')))->get('array-outer', $panel, Volatility::Stable, true, $now))->toHaveCount(1);
    $store->forever('array-outer', $store->get('array-outer'));
    expect((new PermissionSetCache(app('cache')))->get('array-outer', $panel, Volatility::Stable, true, $now))->toHaveCount(1);
    $storage = $store->all(false);
    $storage['array-outer']['value'] = serialize(new EnvelopeWakeupSentinel);
    (new ReflectionProperty($store, 'storage'))->setValue($store, $storage);
    EnvelopeWakeupSentinel::$woken = false;
    expect((new PermissionSetCache(app('cache')))->get('array-outer', $panel, Volatility::Stable, true, $now))->toBeNull()
        ->and(EnvelopeWakeupSentinel::$woken)->toBeFalse()
        ->and($store->all(false))->not->toHaveKey('array-outer');
});

it('bypasses stores whose read transport cannot be inspected safely', function (): void {
    config(['cache.stores.envelope_file' => ['driver' => 'file', 'path' => sys_get_temp_dir().'/azguard-unsupported-cache']]);
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('envelope_file', 60));
    $now = Carbon::now()->toDateTimeImmutable();
    $cache = new PermissionSetCache(app('cache'));
    $cache->put('unsupported', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
    expect($cache->get('unsupported', $panel, Volatility::Stable, true, $now))->toHaveCount(1)
        ->and((new PermissionSetCache(app('cache')))->get('unsupported', $panel, Volatility::Stable, true, $now))->toBeNull()
        ->and(app('cache')->store('envelope_file')->get('unsupported'))->toBeNull();
});

it('verifies Redis bytes before any outer object deserialization', function (): void {
    $prefix = 'azguard-envelope-'.bin2hex(random_bytes(12)).':';
    config(['database.redis.envelope_integrity' => ['host' => '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: 26379, 'database' => 15],
        'cache.stores.envelope_redis' => ['driver' => 'redis', 'connection' => 'envelope_integrity', 'prefix' => $prefix],
        'cache.serializable_classes' => null]);
    $store = app('cache')->store('envelope_redis');
    $connection = $store->getStore()->connection();
    $client = $connection->client();
    expect($client->getOption(Redis::OPT_SERIALIZER))->toBe(Redis::SERIALIZER_NONE)
        ->and($connection->get($prefix.'outer'))->toBeNull();
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('envelope_redis', 60));
    $now = Carbon::now()->toDateTimeImmutable();
    $authorityKey = null;

    try {
        (new PermissionSetCache(app('cache')))->put('outer', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
        expect((new PermissionSetCache(app('cache')))->get('outer', $panel, Volatility::Stable, true, $now))->toHaveCount(1);
        $connection->setex($prefix.'outer', 60, serialize(new EnvelopeWakeupSentinel));
        EnvelopeWakeupSentinel::$woken = false;
        expect((new PermissionSetCache(app('cache')))->get('outer', $panel, Volatility::Stable, true, $now))->toBeNull()
            ->and(EnvelopeWakeupSentinel::$woken)->toBeFalse()
            ->and($connection->get($prefix.'outer'))->toBeNull();

        $connection->setex($prefix.'outer', 60, serialize(new EnvelopeWakeupSentinel));
        $client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        expect((new PermissionSetCache(app('cache')))->get('outer', $panel, Volatility::Stable, true, $now))->toBeNull()
            ->and(EnvelopeWakeupSentinel::$woken)->toBeFalse();
        (new PermissionSetCache(app('cache')))->put('outer', $panel, Volatility::Stable, true, [AuthorizationWorld::grant()], $now);
        $client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        expect($connection->get($prefix.'outer'))->toBe(serialize(new EnvelopeWakeupSentinel));

        $source = new FencedCacheSource;
        $source->reuse = Volatility::Stable;
        [$engine, $authorityPanel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->cache('envelope_redis', 60));
        expect($engine->decide($authorityPanel, $request)->allowed())->toBeFalse();
        $keys = $connection->keys($prefix.'azguard:sets:v2:*');
        expect($keys)->toHaveCount(1);
        $clientPrefix = $client->getOption(Redis::OPT_PREFIX) ?: '';
        expect($keys[0])->toStartWith($clientPrefix.$prefix);
        $authorityKey = substr($keys[0], strlen($clientPrefix));
        app()->forgetScopedInstances();
        expect($engine->decide($authorityPanel, $request)->allowed())->toBeFalse()->and($source->grantReads)->toBe(1);
        $connection->setex($authorityKey, 60, serialize(new EnvelopeWakeupSentinel));
        app()->forgetScopedInstances();
        expect($engine->decide($authorityPanel, $request)->allowed())->toBeFalse()
            ->and($source->grantReads)->toBe(2)->and(EnvelopeWakeupSentinel::$woken)->toBeFalse();
    } finally {
        $client->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        $connection->del($prefix.'outer');

        if ($authorityKey !== null) {
            $connection->del($authorityKey);
        }
    }
})->group('redis');

final class EnvelopeWakeupSentinel
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
