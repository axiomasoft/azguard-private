<?php

declare(strict_types=1);

use AzGuard\Audit\Internal\AuditSchema;
use AzGuard\AzGuard;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AuditSchema::create(app(AzGuard::class)->storage('default'));
    }

    public function down(): void
    {
        AuditSchema::drop(app(AzGuard::class)->storage('default'));
    }
};
