<?php

declare(strict_types=1);

use AzGuard\Configuration\Config;
use AzGuard\Database\Schema\MorphColumns;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('applies explicit morph type length and collation', function (): void {
    $table = 'morph_columns_coverage_'.getmypid();

    try {
        Schema::create($table, function (Blueprint $blueprint): void {
            MorphColumns::add(
                $blueprint,
                'model',
                nullable: true,
                keyTypeLength: 191,
                keyTypeCollation: null,
            );
        });

        expect(Schema::hasColumn($table, 'model_type'))->toBeTrue()
            ->and(Schema::hasColumn($table, 'model_id'))->toBeTrue()
            ->and(Config::morphType())->toBe('int');
    } finally {
        Schema::dropIfExists($table);
    }
});
