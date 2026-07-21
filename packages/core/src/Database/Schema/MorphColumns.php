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
    public static function add(
        Blueprint $table,
        string $name,
        bool $nullable = false,
        ?int $keyTypeLength = null,
        ?string $keyTypeCollation = null,
    ): void {
        if ($keyTypeLength !== null || $keyTypeCollation !== null) {
            self::addWithKeyTypeOptions(
                table: $table,
                name: $name,
                nullable: $nullable,
                keyTypeLength: $keyTypeLength ?? 255,
                keyTypeCollation: $keyTypeCollation,
            );

            return;
        }

        $type = Config::morphType();

        if ($type === 'ulid') {
            if ($nullable) {
                $table->nullableUlidMorphs($name, self::morphIndexName($table, $name));
            } else {
                $table->ulidMorphs($name, self::morphIndexName($table, $name));
            }

            return;
        }

        if ($type === 'uuid') {
            if ($nullable) {
                $table->nullableUuidMorphs($name, self::morphIndexName($table, $name));
            } else {
                $table->uuidMorphs($name, self::morphIndexName($table, $name));
            }

            return;
        }

        if ($nullable) {
            $table->nullableMorphs($name, self::morphIndexName($table, $name));
        } else {
            $table->morphs($name, self::morphIndexName($table, $name));
        }
    }

    private static function addWithKeyTypeOptions(
        Blueprint $table,
        string $name,
        bool $nullable,
        int $keyTypeLength,
        ?string $keyTypeCollation,
    ): void {
        $typeColumn = $table->string("{$name}_type", $keyTypeLength);

        if ($nullable) {
            $typeColumn->nullable();
        }

        if ($keyTypeCollation !== null) {
            $typeColumn->collation($keyTypeCollation);
        }

        $idColumn = match (Config::morphType()) {
            'ulid' => $table->ulid("{$name}_id"),
            'uuid' => $table->uuid("{$name}_id"),
            default => $table->unsignedBigInteger("{$name}_id"),
        };

        if ($nullable) {
            $idColumn->nullable();
        }

        $table->index(["{$name}_type", "{$name}_id"], self::morphIndexName($table, $name));
    }

    private static function morphIndexName(Blueprint $table, string $name): string
    {
        return 'azg_'.sha1($table->getTable().'_'.$name).'_idx';
    }
}
