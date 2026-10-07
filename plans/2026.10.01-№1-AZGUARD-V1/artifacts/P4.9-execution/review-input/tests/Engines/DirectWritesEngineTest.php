<?php

declare(strict_types=1);

use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Storage\SchemaAssertions;

it('blocks server Eloquent write paths before changing rows or panel version', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->create('default');

    try {
        $storage->mutate('admin', function (StorageMutation $mutation): void {
            $mutation->table('role_grants')->insert(SchemaAssertions::grant());
            $mutation->touch('admin');
        });
        $builder = $storage->model('role_grant')->newQuery();
        $model = $builder->firstOrFail();
        $calls = [
            fn () => $builder->insert(SchemaAssertions::grant()), fn () => $builder->insertOrIgnore(SchemaAssertions::grant()),
            fn () => $builder->insertOrIgnoreReturning(SchemaAssertions::grant()), fn () => $builder->updateFrom(['origin' => 'import']),
            fn () => $builder->upsert(SchemaAssertions::grant(), ['id']), fn () => $builder->update(['origin' => 'import']),
            fn () => $builder->delete(), fn () => $builder->forceDelete(), fn () => $builder->truncate(),
            fn () => $builder->increment('id'), fn () => $builder->touch(), fn () => $builder->firstOrCreate(['id' => $model->getKey()]),
            fn () => $model->saveQuietly(), fn () => $model->deleteOrFail(),
        ];
        foreach ($calls as $call) {
            expect($call)->toThrow(UnsupportedDirectWriteException::class);
        }
        expect($builder->count())->toBe(1)->and($builder->firstOrFail()->origin())->toBe('manual')->and($storage->state('admin')->version)->toBe(1);
        $storage->mutate('admin', function (StorageMutation $mutation): void {
            $mutation->table('role_grants')->update(['origin' => 'import']);
            $mutation->touch('admin');
        });
        expect($builder->firstOrFail()->origin())->toBe('import')->and($storage->state('admin')->version)->toBe(2);
    } finally {
        $schema->drop('default');
    }
})->group('engines');
