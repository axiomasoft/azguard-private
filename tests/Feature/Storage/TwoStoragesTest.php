<?php

declare(strict_types=1);

use AzGuard\Storage\Internal\Storage;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Storage\Schema\StorageSchema;

it('migrates writes and drops two prefixed storages independently', function (): void {
    $registry = app(StorageRegistry::class);
    $first = $registry->get('default');
    $second = new Storage('other', $first->connection(), 'adm_');
    $registry->register($second);
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->drop('other');
    $schema->create('default');
    $schema->create('other');

    try {
        foreach ([$first, $second] as $storage) {
            $storage->mutate('admin', fn (StorageMutation $mutation) => $mutation->touch('admin'));
        }
        expect($first->state('admin')->version)->toBe(1)->and($second->state('admin')->version)->toBe(1);
        $schema->drop('default');
        $second->mutate('admin', fn (StorageMutation $mutation) => $mutation->touch('admin'));
        expect($second->state('admin')->version)->toBe(2)->and($second->connection()->getSchemaBuilder()->hasTable('azg_panel_state'))->toBeFalse();
    } finally {
        $schema->drop('default');
        $schema->drop('other');
    }
});
