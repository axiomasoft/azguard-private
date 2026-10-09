<?php

declare(strict_types=1);

use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\Storage;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Support\Facades\DB;

// Acceptance criterion 10 (audits/2026-10-09-consistency-design.md, step 6): a list query whose authority cannot be
// read fails; it never becomes an empty list that looks like "no access".

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('AC10 throws source_error instead of returning an empty list when the grant storage fails', function (): void {
    $storage = Storage::own('secondary');
    app(StorageSchema::class)->create($storage->id());
    [$visibility, $panel] = W::compile(extra: [DatabaseSource::make()->storage($storage)]);
    DB::connection('secondary')->beforeExecuting(static function (string $sql): void {
        if (str_contains($sql, 'azg_')) {
            throw new RuntimeException('The grant storage is unavailable.');
        }
    });

    expect(fn () => $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class, 'source_error');
});
