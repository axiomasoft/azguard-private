<?php

declare(strict_types=1);

use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Models\DirectGrant;
use AzGuard\Tests\Stubs\User;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Proves DirectGrantResource batch-resolves the user label (the "grantable"
 * relation) via a single eager load instead of an N+1 lazy load per row.
 */
function buildDirectGrantTable(): Table
{
    /** @var HasTable $livewire */
    $livewire = Mockery::mock(HasTable::class);

    return DirectGrantResource::table(Table::make($livewire));
}

it('eager-loads the grantable relation on the table query', function (): void {
    $query = DirectGrant::query();

    $scoped = buildDirectGrantTable()->applyQueryScopes($query);

    // modifyQueryUsing(fn ($q) => $q->with('grantable')) must register the
    // relation for eager loading, killing the per-row lazy lookup.
    expect(array_keys($scoped->getEagerLoads()))->toContain('grantable');
});

it('resolves N user labels without an N+1 (one query for grants, one for grantables)', function (): void {
    $users = User::factory()->count(5)->create();

    foreach ($users as $user) {
        DirectGrant::query()->create([
            'grantable_type' => $user::class,
            'grantable_id' => $user->getKey(),
            'panel_id' => 'admin',
            'permission_key' => 'admin.project.view',
            'expires_at' => null,
        ]);
    }

    $query = buildDirectGrantTable()->applyQueryScopes(DirectGrant::query());

    DB::enableQueryLog();
    $grants = $query->get();

    // Touch every grantable — with eager loading this hits no further queries.
    $grants->each(fn (DirectGrant $grant) => $grant->grantable);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($grants)->toHaveCount(5)
        // Without eager loading this would be 1 + 5 (one lazy load per row).
        ->and($queryCount)->toBeLessThanOrEqual(2);
});

it('does not grow the query count as the number of grants grows', function (): void {
    $countQueriesFor = function (int $rows): int {
        DirectGrant::query()->delete();

        $users = User::factory()->count($rows)->create();
        foreach ($users as $user) {
            DirectGrant::query()->create([
                'grantable_type' => $user::class,
                'grantable_id' => $user->getKey(),
                'panel_id' => 'admin',
                'permission_key' => 'admin.project.view',
                'expires_at' => null,
            ]);
        }

        $query = buildDirectGrantTable()->applyQueryScopes(DirectGrant::query());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $query->get()->each(fn (DirectGrant $grant) => $grant->grantable);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // Constant query count regardless of row count is the N+1 signature's inverse.
    expect($countQueriesFor(2))->toBe($countQueriesFor(8));
});
