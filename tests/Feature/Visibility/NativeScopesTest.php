<?php

declare(strict_types=1);

use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class_exists(W::class);

class NativeScopedClient extends VisibilityClient
{
    use SoftDeletes;

    public static int $scopeCalls = 0;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where('visibility_clients.org', 'A'));
        static::addGlobalScope('counting', function (Builder $query): void {
            self::$scopeCalls++;
            $query->where('visibility_clients.id', '>=', 100 + self::$scopeCalls);
        });
    }

    /** @return MorphTo<VisibilityProject, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'target_type', 'project_id');
    }
}

beforeEach(function (): void {
    W::seed();
    Schema::table('visibility_clients', function (Blueprint $table): void {
        $table->softDeletes();
        $table->string('target_type')->nullable();
    });
    DB::table('visibility_clients')->where('id', 103)->update(['deleted_at' => '2026-01-01 00:00:00']);
    DB::table('visibility_clients')->insert(['id' => 105, 'project_id' => 4, 'org' => 'B', 'city' => 'Rome', 'state' => 'allow', 'active' => true, 'deleted_at' => '2026-01-01 00:00:00']);
    DB::table('visibility_clients')->update(['target_type' => VisibilityProject::class]);
    NativeScopedClient::$scopeCalls = 0;
});
afterEach(fn () => W::reset());

it('materializes tenant, soft delete and non-idempotent scopes with explicit host overrides', function (Closure $host, array $ids, array $hostBindings): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]));
    $query = $visibility->visibleTo($panel, $host(), W::subject(), 'orders.view');

    expect(array_slice($query->getBindings(), 0, count($hostBindings)))->toBe($hostBindings)
        ->and($query->orderBy('visibility_clients.id')->pluck('visibility_clients.id')->all())->toBe($ids)
        ->and($query->count())->toBe(count($ids))
        ->and(NativeScopedClient::$scopeCalls)->toBe(1);
})->with([
    'default' => [fn () => NativeScopedClient::query(), [101, 102], ['A', 101]],
    'with trashed' => [fn () => NativeScopedClient::withTrashed(), [101, 102, 103], ['A', 101]],
    'only trashed' => [fn () => NativeScopedClient::onlyTrashed(), [103], ['A', 101]],
    'without trashed' => [fn () => NativeScopedClient::withoutTrashed(), [101, 102], ['A', 101]],
    'without tenant' => [fn () => NativeScopedClient::withoutGlobalScope('tenant'), [101, 102, 104], [101]],
    'without soft delete' => [fn () => NativeScopedClient::withoutGlobalScope(SoftDeletingScope::class), [101, 102, 103], ['A', 101]],
    'without both' => [fn () => NativeScopedClient::withoutGlobalScopes(['tenant', SoftDeletingScope::class]), [101, 102, 103, 104, 105], [101]],
]);

it('preserves scopes through repeated visibility, pagination, cursor and eager relations', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]));
    $query = NativeScopedClient::query()->with(['project' => fn (BelongsTo $q) => $q->where('active', true)])
        ->where(fn (Builder $q) => $q->where('visibility_clients.city', 'Paris')->orWhere('visibility_clients.city', 'Rome'));
    $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
    $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
    expect($query->getBindings())->toContain('A', 101, 'Paris', 'Rome')
        ->and($query->count())->toBe(2)
        ->and($query->orderBy('visibility_clients.id')->paginate(10)->pluck('id')->all())->toBe([101, 102])
        ->and($query->cursor()->pluck('id')->all())->toBe([101, 102]);
    $rows = $query->get();
    expect($rows[0]->relationLoaded('project'))->toBeTrue()->and($rows[0]->project->id)->toBe(1)
        ->and($rows[1]->project)->toBeNull()->and(NativeScopedClient::$scopeCalls)->toBe(1);
});

it('retains whereHas and morph constraints under native scopes', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]));
    $query = NativeScopedClient::query()->whereHas('project', fn (Builder $q) => $q->where('active', true))
        ->whereHasMorph('target', [VisibilityProject::class], fn (Builder $q) => $q->where('city', 'Paris'));
    $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
    expect($query->orderBy('visibility_clients.id')->pluck('visibility_clients.id')->all())->toBe([101])
        ->and($query->getBindings())->toContain(true, 'Paris', 'A', 101)
        ->and(NativeScopedClient::$scopeCalls)->toBe(1);
});

it('preserves a select and join introduced by a global scope', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]));
    $query = NativeScopedClient::query()->withGlobalScope('join', fn (Builder $q) => $q
        ->join('visibility_projects as scoped_projects', 'scoped_projects.id', '=', 'visibility_clients.project_id')
        ->select('visibility_clients.*')->addSelect('scoped_projects.city as project_city'));
    $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
    $rows = $query->orderBy('visibility_clients.id')->get();
    expect($rows->pluck('id')->all())->toBe([101, 102])->and($rows->pluck('project_city')->all())->toBe(['Paris', 'Rome'])
        ->and(NativeScopedClient::$scopeCalls)->toBe(1);
});

it('isolates a raw OR from scopes and authority with the bindings intact', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]));
    $query = NativeScopedClient::query()->whereRaw('visibility_clients.city = ? or visibility_clients.city = ?', ['Rome', 'Paris']);
    $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
    expect(array_slice($query->getBindings(), 0, 4))->toBe(['Rome', 'Paris', 'A', 101])
        ->and($query->orderBy('visibility_clients.id')->pluck('visibility_clients.id')->all())->toBe([101])
        ->and(NativeScopedClient::$scopeCalls)->toBe(1);
});

it('constrains a host OR grouped by Laravel when scopes are applied', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]));
    $query = NativeScopedClient::query()->where('visibility_clients.city', 'Rome')->orWhere('visibility_clients.city', 'Paris');
    $visibility->visibleTo($panel, $query, W::subject(), 'orders.view');
    expect(array_slice($query->getBindings(), 0, 4))->toBe(['Rome', 'Paris', 'A', 101])
        ->and($query->orderBy('visibility_clients.id')->pluck('visibility_clients.id')->all())->toBe([101])
        ->and(NativeScopedClient::$scopeCalls)->toBe(1);
});

it('rejects unsupported host queries without mutating their builder', function (Closure $host, array $ids, int $calls): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]));
    $query = $host()->with('project');
    $base = clone $query->getQuery();
    $eager = $query->getEagerLoads();
    expect(fn () => $visibility->visibleTo($panel, $query, W::subject(), 'orders.view'))->toThrow(VisibilityNotSupportedException::class)
        ->and($query->getQuery())->toEqual($base)->and($query->getEagerLoads())->toBe($eager);
    NativeScopedClient::$scopeCalls = 0;
    expect($query->orderBy('id')->get()->pluck('id')->all())->toBe($ids)
        ->and(NativeScopedClient::$scopeCalls)->toBe($calls);
})->with([
    'top level or' => [fn () => NativeScopedClient::withoutGlobalScopes()->where('visibility_clients.org', 'A')->orWhere('visibility_clients.org', 'B'), [101, 102, 103, 104, 105], 0],
    'union' => [fn () => NativeScopedClient::query()->where('visibility_clients.org', 'A')->union(NativeScopedClient::query()->where('visibility_clients.org', 'A')), [101, 102], 2],
]);
