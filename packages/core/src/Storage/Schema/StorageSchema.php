<?php

declare(strict_types=1);

namespace AzGuard\Storage\Schema;

use AzGuard\Storage\StorageRegistry;
use Illuminate\Database\Schema\Blueprint;

final readonly class StorageSchema
{
    public function __construct(private StorageRegistry $storages) {}

    public function create(string $storage): void
    {
        $storage = $this->storages->get($storage);
        $storage->connection()->getSchemaBuilder()->create($storage->prefix().'panel_state', function (Blueprint $table) use ($storage): void {
            $table->string('panel', 64);
            $table->primary('panel', $storage->prefix().'ps_pk');
            $table->unsignedBigInteger('version')->default(0);
            $table->char('incarnation', 26);
            $table->dateTime('updated_at');
        });
    }

    public function drop(string $storage): void
    {
        $storage = $this->storages->get($storage);
        $storage->connection()->getSchemaBuilder()->dropIfExists($storage->prefix().'panel_state');
    }
}
