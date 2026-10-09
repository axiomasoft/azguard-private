<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\Query\EligibilityBuilder;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\NativeProject;
use AzGuard\Tests\Fixtures\Scopes\NativeScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    expect(config('database.connections.testbench.database'))->toMatch('/^:memory:$|_test$/');
    Schema::create('native_projects', function (Blueprint $table): void {
        $table->id();
        $table->string('owner');
        $table->string('city');
        $table->boolean('active');
    });
    Schema::create('native_members', function (Blueprint $table): void {
        $table->id();
        $table->unsignedInteger('project_id');
        $table->string('name');
    });
    NativeProject::query()->insert([
        ['id' => 1, 'owner' => 'A', 'city' => 'Paris', 'active' => true],
        ['id' => 2, 'owner' => 'B', 'city' => 'Rome', 'active' => true],
        ['id' => 3, 'owner' => 'A', 'city' => 'Paris', 'active' => false],
    ]);
    DB::table('native_members')->insert(['project_id' => 1, 'name' => 'Ada']);
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)]);
    $this->nativeDefinition = new NativeScope;
    $this->nativeResolved = $this->nativeDefinition->resolve(AssignmentScopeRef::of('native.project', 1));
    $this->nativeRuntime = new AssignmentScopeRuntime(
        $registry->get('admin'), AccessScope::in(TenantRef::of('org', 'A'), AssignmentScopeRef::of('native.project', 1)),
        SubjectRef::of('user', 1), null, null, null, ActorRef::system(), null,
        new DateTimeImmutable('2026-10-06T12:00:00Z'), AssignmentScopePhase::Access,
    );
    DB::flushQueryLog();
    DB::enableQueryLog();
});

it('supports native grouped predicates, local scopes, relationships and bound subqueries', function (): void {
    $result = EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [
        function (Builder $query): void {
            $query->active()->where(fn (Builder $nested) => $nested->where('city', 'missing')->orWhere('city', 'Paris'));
            $query->whereHas('members', fn (Builder $members) => $members->where('name', 'Ada'));
            $query->whereIn('id', function (Query $subquery): void {
                $subquery->select('project_id')->from('native_members')->where('name', 'Ada');
            });
            $query->whereExists(function (Query $subquery): void {
                $subquery->selectRaw('1')->from('native_members')->whereColumn('project_id', 'native_projects.id')->where('name', 'Ada');
            });
        },
    ], $this->nativeRuntime, app());

    expect($result)->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(2)
        ->and(DB::getQueryLog()[1]['query'])->toContain('exists', 'native_members')
        ->and(DB::getQueryLog()[1]['bindings'])->toContain('Paris', 'Ada');
});

it('keeps OR inside its own predicate and preserves identity and other filters', function (): void {
    expect(EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [
        fn (Builder $query) => $query->where('city', 'missing'),
        fn (Builder $query) => $query->orWhere('city', 'Paris'),
    ], $this->nativeRuntime, app()))->toBeFalse()
        ->and(EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [
            fn (Builder $query) => $query->orWhere('id', 2),
        ], $this->nativeRuntime, app()))->toBeFalse();
});

it('reads fresh fields and rechecks authoritative owner instead of a stale resolved model', function (): void {
    NativeProject::query()->whereKey(1)->update(['active' => false]);
    expect(EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [
        fn (Builder $query) => $query->active(),
    ], $this->nativeRuntime, app()))->toBeFalse();
    NativeProject::query()->whereKey(1)->update(['owner' => 'B', 'active' => true]);
    expect(EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [], $this->nativeRuntime, app()))->toBeFalse();
});

it('injects query-first reserved runtime slots and refuses a missing required user', function (): void {
    expect(EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [
        function (Builder $query, ?User $user, AssignmentScopeRuntime $runtime): void {
            expect($user)->toBeNull()->and($runtime->subject->id())->toBe('1');
            $query->where('active', true);
        },
    ], $this->nativeRuntime, app()))->toBeTrue();
    expect(fn () => EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [
        fn (Builder $query, User $user) => $query->where('id', $user->getKey()),
    ], $this->nativeRuntime, app()))->toThrow(RuntimeException::class, 'user');
});

it('rejects altered root structure and replacement queries', function (Closure $filter): void {
    expect(fn () => EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [$filter], $this->nativeRuntime, app()))
        ->toThrow(RuntimeException::class);
})->with([
    'from' => [fn (Builder $query) => $query->from('native_members')],
    'connection' => [function (Builder $query): void {
        $query->getQuery()->connection = DB::connection('secondary');
    }],
    'join' => [fn (Builder $query) => $query->join('native_members', 'project_id', '=', 'native_projects.id')],
    'select' => [fn (Builder $query) => $query->select('city')],
    'union' => [fn (Builder $query) => $query->union(NativeProject::query())],
    'order' => [fn (Builder $query) => $query->orderBy('id')],
    'limit' => [fn (Builder $query) => $query->limit(1)],
    'offset' => [fn (Builder $query) => $query->offset(1)],
    'group' => [fn (Builder $query) => $query->groupBy('city')],
    'replace' => [fn (Builder $query) => NativeProject::query()],
    'setQuery' => [fn (Builder $query) => $query->setQuery(DB::table('native_members'))],
    'pending global scope' => [fn (Builder $query) => $query->withGlobalScope('deny', fn (Builder $scoped) => $scoped->whereRaw('0 = 1'))],
    'after query callback' => [fn (Builder $query) => $query->afterQuery(fn () => null)],
    'nested from' => [fn (Builder $query) => $query->where(fn (Builder $nested) => $nested->from('native_members'))],
]);

it('blocks terminal operations before SQL including the public base query', function (Closure $filter): void {
    expect(fn () => EligibilityBuilder::matches($this->nativeDefinition, $this->nativeResolved, [$filter], $this->nativeRuntime, app()))
        ->toThrow(RuntimeException::class);
    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(NativeProject::query()->count())->toBe(3);
})->with([
    'get' => [fn (Builder $query) => $query->get()],
    'exists' => [fn (Builder $query) => $query->exists()],
    'count' => [fn (Builder $query) => $query->count()],
    'cursor' => [fn (Builder $query) => $query->cursor()],
    'base get' => [fn (Builder $query) => $query->getQuery()->get()],
    'base exists' => [fn (Builder $query) => $query->getQuery()->exists()],
    'base cursor' => [fn (Builder $query) => $query->getQuery()->cursor()],
    'base delete' => [fn (Builder $query) => $query->getQuery()->delete()],
    'base update' => [fn (Builder $query) => $query->getQuery()->update(['active' => false])],
    'create' => [fn (Builder $query) => $query->create(['owner' => 'A', 'city' => 'Oslo', 'active' => true])],
    'nested get' => [fn (Builder $query) => $query->where(fn (Builder $nested) => $nested->get())],
    'relation get' => [fn (Builder $query) => $query->whereHas('members', fn (Builder $members) => $members->get())],
    'caught get' => [function (Builder $query): void {
        try {
            $query->getQuery()->get();
        } catch (RuntimeException) {
            $query->where('active', true);
        }
    }],
]);
