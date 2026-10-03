<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Storage\DdlSnapshot;
use AzGuard\Tests\Fixtures\Storage\SchemaAssertions;

it('creates the complete grant action and state schema with identity and pair integrity', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->create('default');

    try {
        SchemaAssertions::verify($storage);
    } finally {
        $schema->drop('default');
    }
});

it('keeps generated names within 63 bytes with the maximum prefix', function (): void {
    $registry = app(StorageRegistry::class);
    $storage = new Storage('long', $registry->get('default')->connection(), str_repeat('x', 20));
    $registry->register($storage);
    $schema = app(StorageSchema::class);
    $schema->drop('long');
    $schema->create('long');

    try {
        foreach (['permissions', 'role_grants', 'permission_grants', 'panel_state', 'storage_state'] as $base) {
            foreach ($storage->connection()->getSchemaBuilder()->getIndexes($storage->prefix().$base) as $index) {
                expect(strlen($index['name']))->toBeLessThanOrEqual(63);
            }
        }

        if ($storage->connection()->getDriverName() === 'sqlite') {
            foreach ($storage->connection()->select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name LIKE ?", [$storage->prefix().'%']) as $trigger) {
                expect(strlen($trigger->name))->toBeLessThanOrEqual(63);
            }
        }
    } finally {
        $schema->drop('long');
    }
});

it('matches SQLite DDL including pair triggers and host key columns', function (): void {
    if (env('DB_CONNECTION', 'sqlite') !== 'sqlite') {
        $this->markTestSkipped('SQLite DDL is verified by the SQLite suite; server DDL is in engines.');
    }
    DdlSnapshot::verify();
});
