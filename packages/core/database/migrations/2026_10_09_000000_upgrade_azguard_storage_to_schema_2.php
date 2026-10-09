<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Schema 2 adds subject revisions and the panel epoch. A storage created by the first migration at schema 2 is left
 * as it is; a storage created at schema 1 is upgraded in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(StorageSchema::class)->upgrade('default');
    }

    public function down(): void
    {
        // Schema 2 is not downgraded: an older release refuses a storage it does not know.
    }
};
