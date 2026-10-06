<?php

declare(strict_types=1);

use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

it('P10b supported root sees its own changes and committed revoke survives a new request', function (): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::seedSubject();
    [$engine, $panel] = CacheWorld::database(DatabaseSource::make());
    $storage = DatabaseWorld::storage();
    $engine->withinAuthorityTransaction($panel, function () use ($engine, $panel): void {
        DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
    });
    $storage->mutate('admin', function (StorageMutation $m) use ($engine, $panel): void {
        $m->table('permission_grants')->delete();
        expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
        $m->touch('admin');
    });
    app()->forgetScopedInstances();
    expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeFalse();
});
