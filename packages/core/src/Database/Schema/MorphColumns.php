<?php

declare(strict_types=1);

namespace AzGuard\Database\Schema;

use AzGuard\Configuration\Config;
use Illuminate\Database\Schema\Blueprint;

/**
 * Adds a polymorphic column pair (`{name}_type` + `{name}_id`) whose key type
 * follows config('az-guard.column_names.morph_type'): int (default), ULID or
 * UUID. Lets a host app line AzGuard's morphs up with its models' key type
 * without forking the package migrations.
 */
final class MorphColumns
{
    public static function add(Blueprint $table, string $name, bool $nullable = false): void
    {
        $type = Config::morphType();

        if ($type === 'ulid') {
            if ($nullable) {
                $table->nullableUlidMorphs($name);
            } else {
                $table->ulidMorphs($name);
            }

            return;
        }

        if ($type === 'uuid') {
            if ($nullable) {
                $table->nullableUuidMorphs($name);
            } else {
                $table->uuidMorphs($name);
            }

            return;
        }

        if ($nullable) {
            $table->nullableMorphs($name);
        } else {
            $table->morphs($name);
        }
    }
}
