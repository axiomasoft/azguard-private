<?php

declare(strict_types=1);

use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('preserves witnesses at the contribution boundary with balanced OR branches', function (int $count): void {
    $source = new VisibilitySource(direct: array_map(static fn (int $id) => W::grant($id), range(1, $count)));
    [$visibility, $panel] = W::compile($source);
    $query = $visibility->visibleTo($panel, VisibilityProject::query()->whereIn('id', [1, 4]), W::subject(), 'orders.view');
    expect($query->orderBy('id')->pluck('id')->all())->toBe([1, 4])
        ->and(count($query->getBindings()))->toBeLessThan(5000)
        ->and(strlen($query->toSql()))->toBeLessThan(500000);
})->with([999, 1000]);

it('refuses excess distinct or repeated witnesses before changing or executing the host builder', function (int $count, bool $repeated): void {
    $source = new VisibilitySource(direct: array_map(static fn (int $id) => W::grant($repeated ? 1 : $id), range(1, $count)));
    [$visibility, $panel] = W::compile($source);
    $query = VisibilityProject::query()->where('city', 'Paris')->with('members')->orderBy('id');
    $sql = $query->toSql();
    $bindings = $query->getBindings();
    $eager = $query->getEagerLoads();
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();
    expect(fn () => $visibility->visibleTo($panel, $query, W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class, 'selection_budget')
        ->and($query->toSql())->toBe($sql)->and($query->getBindings())->toBe($bindings)->and($query->getEagerLoads())->toBe($eager);
    foreach (DB::connection()->getQueryLog() as $entry) {
        expect($entry['query'])->not->toContain('visibility_projects');
    }
    DB::connection()->disableQueryLog();
})->with([[1001, false], [10000, false], [1001, true], [10000, true]]);

it('shares the witness budget across sources and counts tenant-wide witnesses', function (): void {
    $first = new VisibilitySource(direct: array_fill(0, 500, W::grant()));
    $second = new VisibilitySource(name: 'second', direct: array_fill(0, 501, W::grant(source: 'second')));
    [$visibility, $panel] = W::compile($first, extra: [$second]);
    $query = VisibilityProject::query()->where('id', 1);
    $sql = $query->toSql();
    expect(fn () => $visibility->visibleTo($panel, $query, W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class, 'selection_budget')
        ->and($query->toSql())->toBe($sql)->and($query->getBindings())->toBe([1]);
});
