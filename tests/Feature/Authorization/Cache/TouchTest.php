<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

it('invalidates code-derived Request contributions on touch only after root commit', function (): void {
    app(StorageSchema::class)->create('default');
    $source = new CacheSource(direct: [AuthorizationWorld::grant()]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $p) => $p->cache('array'));
    $storage = DatabaseWorld::storage();
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    $source->direct = [];
    $reads = $source->grantReads;
    $storage->mutate('admin', function (StorageMutation $mutation) use ($engine, $panel, $request, $source, $reads): void {
        $mutation->touch('admin');
        expect($engine->decide($panel, $request)->allowed())->toBeTrue()->and($source->grantReads)->toBe($reads);
    });
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted)
        ->and($source->grantReads)->toBe($reads + 1);
});

it('keeps code-derived Request memo unchanged on no-op and rolled back touch', function (bool $rollback): void {
    app(StorageSchema::class)->create('default');
    $source = new CacheSource(direct: [AuthorizationWorld::grant()]);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $p) => $p->cache('array'));
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    $source->direct = [];
    $reads = $source->grantReads;

    try {
        DatabaseWorld::storage()->mutate('admin', function (StorageMutation $mutation) use ($rollback): void {
            if ($rollback) {
                $mutation->touch('admin');

                throw new RuntimeException('rollback');
            }
        });
    } catch (RuntimeException) {
    }
    expect($engine->decide($panel, $request)->allowed())->toBeTrue()->and($source->grantReads)->toBe($reads);
})->with([false, true]);
