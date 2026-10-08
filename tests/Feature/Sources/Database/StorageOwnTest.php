<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

it('reuses a configured physical storage and checks its host key contract', function (): void {
    $default = app(StorageRegistry::class)->get('default');
    expect(Storage::own())->toBe($default)
        ->and(Storage::own(connection: $default->connectionName(), prefix: $default->prefix()))->toBe($default)
        ->and(fn () => Storage::own(connection: $default->connectionName(), hostKeys: 'bigint'))->toThrow(InvalidConfigurationException::class);
});

it('derives stable canonical ids and keeps two prefixes on the same connection distinct', function (): void {
    $first = Storage::own(prefix: 'first_');
    $second = Storage::own(prefix: 'second_');
    $expected = 'own-'.substr(hash('sha256', IdentityCodec::compose([$first->connectionName(), 'first_'])), 0, 60);
    expect($first->id())->toBe($expected)->and(strlen($first->id()))->toBe(64)
        ->and(Storage::own(prefix: 'first_'))->toBe($first)->and($first->id())->not->toBe($second->id())
        ->and(app(StorageRegistry::class)->get($first->id()))->toBe($first);
});

it('uses an explicitly owned source storage without reading the default tables', function (): void {
    $own = Storage::own(prefix: 'owned_');
    app(StorageSchema::class)->create($own->id());

    try {
        $source = DatabaseSource::make()->storage($own);
        [$panel] = DatabaseWorld::compile($source);
        expect($source->state($panel, TenantRef::global())->storageId)->toBe($own->id())
            ->and(DatabaseWorld::storage()->connection()->getSchemaBuilder()->hasTable('azg_panel_state'))->toBeFalse();
    } finally {
        app(StorageSchema::class)->drop($own->id());
    }
});

it('fails explicitly if an own hash id is occupied by a different physical pair', function (): void {
    $connection = DatabaseWorld::storage()->connection();
    $id = 'own-'.substr(hash('sha256', IdentityCodec::compose([$connection->getName(), 'collision_'])), 0, 60);
    app(StorageRegistry::class)->register(new Storage($id, $connection, 'occupied_'));

    expect(fn () => Storage::own(prefix: 'collision_'))->toThrow(StorageMismatchException::class);
});
