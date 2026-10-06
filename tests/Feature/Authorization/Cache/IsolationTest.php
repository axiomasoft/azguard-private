<?php

declare(strict_types=1);

use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\FencedCacheSource;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Support\Carbon;

it('reuses Request and revisioned Stable raw contributions and always rereads Volatile', function (Volatility $reuse, bool $fenced, int $sameRequest, int $nextRequest): void {
    $source = $fenced ? new FencedCacheSource : new CacheSource;
    $source->reuse = $reuse;
    $source->direct = [AuthorizationWorld::grant()];
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->cache('array'));
    expect($engine->decide($panel, $request)->allowed())->toBeTrue()
        ->and($engine->decide($panel, $request)->allowed())->toBeTrue()
        ->and($source->grantReads)->toBe($sameRequest)->and($source->roleReads)->toBe($sameRequest);
    app()->forgetScopedInstances();
    $decision = $engine->decide($panel, $request);
    expect($decision->allowed())->toBeTrue()->and($source->grantReads)->toBe($nextRequest);

    if (! $fenced) {
        expect($decision->state)->toBeInstanceOf(CodeStateToken::class);
    }
})->with([
    [Volatility::Stable, true, 1, 1],
    [Volatility::Request, true, 1, 2],
    [Volatility::Request, false, 1, 2],
    [Volatility::Stable, false, 1, 2],
    [Volatility::Volatile, true, 2, 3],
    [Volatility::Volatile, false, 2, 3],
]);

it('persists only Stable fenced sets and resets request-only sets with the scope', function (Volatility $reuse, bool $fenced, bool $persistent): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array'));
    $cache = app(PermissionSetCache::class);
    $now = Carbon::now()->toDateTimeImmutable();
    $cache->put('storage-contract', $panel, $reuse, $fenced, [AuthorizationWorld::grant()], $now);
    expect(app('cache')->store('array')->has('storage-contract'))->toBe($persistent);
    app()->forgetScopedInstances();
    expect(app(PermissionSetCache::class))->not->toBe($cache);
    expect(app(PermissionSetCache::class)->get('storage-contract', $panel, $reuse, $fenced, $now) !== null)->toBe($persistent);
})->with([
    [Volatility::Stable, true, true], [Volatility::Stable, false, false],
    [Volatility::Request, true, false], [Volatility::Request, false, false],
    [Volatility::Volatile, true, false], [Volatility::Volatile, false, false],
]);

it('stores a serializable raw shape containing only kernel contributions and declared scalar fields', function (): void {
    [, $panel] = AuthorizationWorld::compile(new CacheSource, fn (PanelBuilder $panel) => $panel->cache('array'));
    $now = Carbon::now()->toDateTimeImmutable();
    $grant = AuthorizationWorld::grant(fields: ['city' => 'Volgograd', 'ids' => ['007', 7], 'flag' => true, 'missing' => null]);
    app(PermissionSetCache::class)->put('raw-shape', $panel, Volatility::Stable, true, [$grant], $now);
    $payload = app('cache')->store('array')->get('raw-shape');
    expect(array_keys($payload))->toBe(['items', 'validUntil'])->and($payload['items'])->toBe([$grant]);
    $serialized = serialize($payload);
    expect($serialized)->not->toContain('Eloquent', 'Builder', 'Closure', 'AzGuard\\Kernel\\Decision\\Decision', 'EvaluationFrame', 'Policy');
    $restored = unserialize($serialized);
    expect($restored['items'][0]->fields())->toBe($grant->fields());
});

it('does not publish a partial set after a late malformed contribution', function (): void {
    $source = new FencedCacheSource;
    $source->reuse = Volatility::Stable;
    $source->read = static function (): iterable {
        yield AuthorizationWorld::grant();
        yield new stdClass;
    };
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->cache('array'));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError);
    $source->read = null;
    $source->direct = [];
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted)
        ->and($source->grantReads)->toBe(2);
});

it('does not publish an earlier successful source when a later source fails', function (): void {
    $good = new FencedCacheSource;
    $good->reuse = Volatility::Stable;
    $good->direct = [AuthorizationWorld::grant()];
    $bad = new FencedCacheSource(name: 'later');
    $bad->reuse = Volatility::Stable;
    $bad->read = static fn (): never => throw new RuntimeException('later source unavailable');
    [$engine, $panel, $request] = AuthorizationWorld::compile($good, fn (PanelBuilder $panel) => $panel->cache('array'), extra: [$bad]);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError);
    $good->direct = [];
    $bad->read = null;
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted)->and($good->grantReads)->toBe(2);
});

it('keeps two physical authority storages and subjects from sharing warmed DB grants', function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $request = DatabaseWorld::request();
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    $otherSubject = AccessRequest::for(SubjectRef::of('user', 999), $request->permission());
    expect($engine->decide($panel, $otherSubject)->reason)->toBe(DecisionReason::NotGranted);
    $owned = Storage::own(prefix: 'isolated_');
    app(StorageSchema::class)->create($owned->id());
    [$otherEngine, $otherPanel] = CacheWorld::database(DatabaseSource::make()->storage($owned));
    expect($otherEngine->decide($otherPanel, $request)->reason)->toBe(DecisionReason::NotGranted);
});
