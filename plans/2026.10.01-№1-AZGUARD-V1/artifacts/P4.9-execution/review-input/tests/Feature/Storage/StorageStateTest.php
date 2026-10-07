<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\StorageMismatchException;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;

it('rejects absent or incompatible storage state before work starts', function (string $changed): void {
    $storage = app(StorageRegistry::class)->get('default');
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->create('default');
    $called = false;

    try {
        if ($changed === 'table') {
            $storage->connection()->getSchemaBuilder()->drop('azg_storage_state');
        } elseif ($changed === 'row') {
            $storage->table('storage_state')->delete();
        } elseif ($changed === 'malformed') {
            $storage->table('storage_state')->update(['schema' => '[]']);
        } elseif ($changed === 'host_keys') {
            config()->set('azguard.storages.default.host_keys', 'uuid');
            app()->forgetInstance(AzGuardConfig::class);
            app()->forgetInstance(StorageRegistry::class);
            $storage = app(StorageRegistry::class)->get('default');
        } else {
            $found = $storage->schema();
            $found[$changed] = in_array($changed, ['version', 'identity_codec']) ? 2 : 'other';
            $storage->table('storage_state')->update(['schema' => json_encode($found)]);
        }
        $work = function () use (&$called): void {
            $called = true;
        };
        expect(fn () => $storage->mutate('admin', $work))->toThrow(StorageMismatchException::class);
        expect(fn () => $storage->state('admin'))->toThrow(StorageMismatchException::class);
        expect($called)->toBeFalse();
    } finally {
        $schema->drop('default');
    }
})->with(['table', 'row', 'malformed', 'host_keys', 'storage_id', 'prefix', 'identity_codec', 'version']);

it('checks schema once per object and accepts reordered JSON keys', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->create('default');

    try {
        $storage->table('storage_state')->update(['schema' => json_encode(array_reverse($storage->schema(), true))]);
        expect($storage->state('admin'))->toBeNull();
        $storage->table('storage_state')->delete();
        $storage->mutate('admin', fn () => null);
        expect($storage->state('admin')->version)->toBe(0);
    } finally {
        $schema->drop('default');
    }
});
