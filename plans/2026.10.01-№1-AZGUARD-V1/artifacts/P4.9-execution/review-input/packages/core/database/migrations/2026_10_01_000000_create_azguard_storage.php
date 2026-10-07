<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(StorageSchema::class)->create('default');
        // Add host columns here after create(): Schema::connection(...)->table(...).
    }

    public function down(): void
    {
        app(StorageSchema::class)->drop('default');
    }
};
