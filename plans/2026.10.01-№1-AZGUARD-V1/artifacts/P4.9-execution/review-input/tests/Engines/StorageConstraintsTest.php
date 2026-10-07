<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Storage\SchemaAssertions;

it('enforces byte identities and tenant context pair constraints on the engine', function (): void {
    $storage = app(StorageRegistry::class)->get('default');
    $schema = app(StorageSchema::class);
    $schema->drop('default');
    $schema->create('default');

    try {
        SchemaAssertions::verify($storage);
    } finally {
        $schema->drop('default');
    }
})->group('engines');
