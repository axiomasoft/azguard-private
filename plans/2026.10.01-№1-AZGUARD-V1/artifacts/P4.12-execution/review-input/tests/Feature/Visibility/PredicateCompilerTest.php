<?php

declare(strict_types=1);

use AzGuard\Authorization\Query\PredicateCompiler;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PredicateResource extends Model
{
    protected $table = 'predicate_resources';

    public $timestamps = false;

    /** @return BelongsTo<PredicateProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(PredicateProject::class, 'project_id');
    }

    /** @return BelongsTo<PredicateResource, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsTo<PredicateForeignProject, $this> */
    public function foreignProject(): BelongsTo
    {
        return $this->belongsTo(PredicateForeignProject::class, 'project_id');
    }
}

class PredicateProject extends Model
{
    protected $table = 'predicate_projects';

    public $timestamps = false;

    /** @return HasMany<PredicateMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(PredicateMember::class, 'project_id');
    }
}

class PredicateForeignProject extends PredicateProject
{
    protected $connection = 'secondary';
}

class PredicateMember extends Model
{
    protected $table = 'predicate_members';

    public $timestamps = false;
}

beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    Schema::create('predicate_resources', function (Blueprint $table): void {
        $table->id();
        $table->integer('tenant_id');
        $table->integer('project_id')->nullable();
        $table->integer('parent_id')->nullable();
        $table->string('city')->nullable();
        $table->string('state')->nullable();
        $table->integer('score')->nullable();
    });
    Schema::create('predicate_projects', function (Blueprint $table): void {
        $table->id();
        $table->boolean('active')->nullable();
    });
    Schema::create('predicate_members', function (Blueprint $table): void {
        $table->id();
        $table->integer('project_id');
        $table->boolean('active')->nullable();
    });
    PredicateResource::query()->insert([
        ['id' => 1, 'tenant_id' => 1, 'project_id' => 1, 'parent_id' => null, 'city' => 'Paris', 'state' => 'allow', 'score' => 10],
        ['id' => 2, 'tenant_id' => 1, 'project_id' => 2, 'parent_id' => 1, 'city' => 'Rome', 'state' => 'deny', 'score' => 20],
        ['id' => 3, 'tenant_id' => 1, 'project_id' => 1, 'parent_id' => 2, 'city' => null, 'state' => null, 'score' => null],
        ['id' => 4, 'tenant_id' => 2, 'project_id' => 2, 'parent_id' => null, 'city' => 'Paris', 'state' => 'allow', 'score' => 30],
        ['id' => 5, 'tenant_id' => 1, 'project_id' => null, 'parent_id' => null, 'city' => "x' OR 1=1 --", 'state' => 'allow', 'score' => 5],
    ]);
    PredicateProject::query()->insert([['id' => 1, 'active' => true], ['id' => 2, 'active' => false]]);
    PredicateMember::query()->insert([
        ['id' => 1, 'project_id' => 1, 'active' => true], ['id' => 2, 'project_id' => 2, 'active' => false],
    ]);
});

/** @return list<int> */
function predicateIds(P $predicate): array
{
    return (new PredicateCompiler)->constrain(PredicateResource::query(), $predicate)->orderBy('id')->pluck('id')->all();
}

it('compiles total boolean comparisons and their complements including NULL', function (P $predicate, array $ids): void {
    expect(predicateIds($predicate))->toBe($ids)
        ->and(predicateIds(P::not($predicate)))->toBe(array_values(array_diff([1, 2, 3, 4, 5], $ids)))
        ->and(predicateIds(P::not(P::not($predicate))))->toBe($ids);
})->with([
    'pass' => [P::pass(), [1, 2, 3, 4, 5]], 'deny' => [P::deny(), []],
    'empty all' => [P::all(), [1, 2, 3, 4, 5]], 'empty any' => [P::any(), []],
    'eq' => [P::eq('city', 'Paris'), [1, 4]], 'eq NULL' => [P::eq('city', null), [3]],
    'is NULL' => [P::isNull('city'), [3]], 'not NULL' => [P::notNull('city'), [1, 2, 4, 5]],
    'in' => [P::in('score', [10, 20]), [1, 2]], 'in NULL' => [P::in('score', [10, null]), [1, 3]],
    'in only NULL' => [P::in('score', [null]), [3]], 'empty in' => [P::in('score', []), []],
    'gte' => [P::gte('score', 20), [2, 4]], 'lt' => [P::lt('score', 20), [1, 5]],
    'range' => [P::all(P::gte('score', 10), P::lt('score', 30)), [1, 2]],
    'any' => [P::any(P::isNull('score'), P::eq('city', 'Paris')), [1, 3, 4]],
    'exists' => [P::exists('project', P::eq('active', true)), [1, 3]],
    'exists single any' => [P::exists('project', P::any(P::eq('active', true))), [1, 3]],
    'exists any' => [P::exists('project', P::any(P::eq('active', true), P::eq('active', false))), [1, 2, 3, 4]],
    'nested exists any' => [P::exists('project', P::exists('members', P::any(P::eq('active', true)))), [1, 3]],
    'nested relation' => [P::exists('project.members', P::eq('active', true)), [1, 3]],
    'nested exists' => [P::exists('project', P::exists('members', P::eq('active', true))), [1, 3]],
    'self relation' => [P::exists('parent', P::eq('city', 'Paris')), [2]],
]);

it('keeps bound SQL-looking values literal', function (): void {
    $value = "x' OR 1=1 --";
    $query = (new PredicateCompiler)->constrain(PredicateResource::query(), P::eq('city', $value));
    expect($query->getBindings())->toBe([$value])->and($query->toSql())->not->toContain($value)
        ->and($query->pluck('id')->all())->toBe([5]);
});

it('preserves the grouped host WHERE and all non-WHERE query settings', function (): void {
    $query = PredicateResource::query()->where('tenant_id', 1)
        ->where(fn (Builder $query) => $query->where('city', 'Rome')->orWhereNull('city'))
        ->select('id')->orderBy('id')->limit(20)->offset(0);
    $before = $query->getQuery()->wheres;
    $shape = predicateHostShape($query);
    $compiler = new PredicateCompiler;
    expect($compiler->constrain($query, P::any(P::eq('state', 'allow'), P::isNull('state'))))->toBe($query)
        ->and(array_slice($query->getQuery()->wheres, 0, count($before)))->toBe($before)
        ->and($query->getQuery()->wheres[array_key_last($query->getQuery()->wheres)]['boolean'])->toBe('and')
        ->and(predicateHostShape($query))->toBe($shape)
        ->and($query->pluck('id')->all())->toBe([3]);
});

/** @param Builder<*> $query
 * @return array<string, mixed>
 */
function predicateHostShape(Builder $query): array
{
    $shape = get_object_vars($query->getQuery());
    unset($shape['wheres'], $shape['bindings']);

    return $shape;
}

it('rejects ungrouped host OR without weakening it', function (): void {
    $query = PredicateResource::query()->where('tenant_id', 2)->orWhere('city', 'Paris');
    $sql = $query->toSql();
    $bindings = $query->getBindings();
    expect(fn () => (new PredicateCompiler)->constrain($query, P::deny()))->toThrow(DefinitionException::class)
        ->and($query->toSql())->toBe($sql)->and($query->getBindings())->toBe($bindings);
});

it('rejects host UNION arms before appending a predicate', function (bool $all): void {
    $query = PredicateResource::query()->where('tenant_id', 1);
    $query->union(PredicateResource::query()->where('tenant_id', 2), $all);
    $sql = $query->toSql();
    $bindings = $query->getBindings();
    expect(fn () => (new PredicateCompiler)->constrain($query, P::deny()))->toThrow(DefinitionException::class)
        ->and($query->toSql())->toBe($sql)->and($query->getBindings())->toBe($bindings);
})->with([false, true]);

it('rejects UNION introduced by a deferred global scope atomically', function (): void {
    $query = PredicateResource::query()->where('id', 1)->withGlobalScope('union', function (Builder $query): void {
        $query->union(PredicateResource::query()->where('id', 2));
    });
    $wheres = $query->getQuery()->wheres;
    $bindings = $query->getQuery()->getBindings();
    expect(fn () => (new PredicateCompiler)->constrain($query, P::deny()))->toThrow(DefinitionException::class)
        ->and($query->getQuery()->wheres)->toBe($wheres)
        ->and($query->getQuery()->getBindings())->toBe($bindings);
});

it('materializes supported global scopes once before attaching the predicate', function (): void {
    $calls = 0;
    $query = PredicateResource::query()->withGlobalScope('tenant', function (Builder $query) use (&$calls): void {
        $calls++;
        $query->where('tenant_id', 1);
    });
    (new PredicateCompiler)->constrain($query, P::any(P::eq('city', 'Paris'), P::eq('city', 'Rome')));
    expect($query->orderBy('id')->pluck('id')->all())->toBe([1, 2])->and($calls)->toBe(1);
    expect($query->count())->toBe(2)->and($calls)->toBe(1);
});

it('rejects unsupported or unknown nodes atomically even behind a constant', function (P $predicate): void {
    $query = PredicateResource::query()->where('tenant_id', 1);
    $sql = $query->toSql();
    $bindings = $query->getBindings();
    expect(fn () => (new PredicateCompiler)->constrain($query, $predicate))->toThrow(DefinitionException::class)
        ->and($query->toSql())->toBe($sql)->and($query->getBindings())->toBe($bindings);
})->with([
    [P::unsupported()], [P::any(P::pass(), P::unsupported())], [P::all(P::deny(), P::unsupported())],
    [P::all(P::eq('city', 'Paris'), P::eq('missing', 1))], [P::exists('missing', P::pass())],
    [P::exists('project.missing', P::pass())], [P::exists('delete', P::pass())],
    [P::exists('save', P::pass())], [P::exists('foreignProject', P::pass())],
]);

it('rejects partition trees until the caller chooses an outcome', function (): void {
    expect(fn () => (new PredicateCompiler)->constrain(PredicateResource::query(), P::policyResult(true)))
        ->toThrow(InvalidSourceContributionException::class);
});

it('rejects replaced and aliased roots without mutating their queries', function (string $from): void {
    $query = PredicateResource::query()->from($from)->where('id', 1);
    $sql = $query->toSql();
    expect(fn () => (new PredicateCompiler)->constrain($query, P::eq('id', 1)))->toThrow(DefinitionException::class)
        ->and($query->toSql())->toBe($sql);
})->with(['predicate_projects', 'predicate_resources as resource']);

it('maps boolean NULL and Laravel Response policy outcomes into exhaustive disjoint sets', function (): void {
    $adapter = new class implements FiltersAccessQueries
    {
        public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
        {
            return P::partition(P::eq('state', 'allow'), P::eq('state', 'deny'), P::isNull('state'));
        }

        public function scalar(PredicateResource $row): ?Response
        {
            return match ($row->state) {
                'allow' => Response::allow(), 'deny' => Response::deny('denied'), default => null,
            };
        }
    };
    // The adapter declares exact semantics; its acceptance corpus proves both directions.
    $partition = $adapter->predicate(AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', 'orders.view')), PredicateResource::class, Mockery::mock(EvaluationContext::class));
    $sets = [];
    foreach (['allow', 'deny', 'abstain'] as $outcome) {
        $sets[$outcome] = predicateIds($partition->outcome($outcome));
    }
    expect($sets)->toBe(['allow' => [1, 4, 5], 'deny' => [2], 'abstain' => [3]]);
    foreach (PredicateResource::query()->get() as $row) {
        $response = $adapter->scalar($row);
        $outcome = $response === null ? 'abstain' : ($response->allowed() ? 'allow' : 'deny');
        expect(array_filter($sets, fn (array $ids): bool => in_array($row->id, $ids, true)))->toHaveCount(1)
            ->and($sets[$outcome])->toContain($row->id);
    }
    expect(predicateIds(P::not($partition->outcome('deny'))))->toBe([1, 3, 4, 5]);
});

it('before pass constrains rows without creating policy authority', function (): void {
    $before = P::beforePartition(P::gte('score', 20), P::not(P::gte('score', 20)));
    expect(predicateIds($before->outcome('deny')))->toBe([2, 4])
        ->and(predicateIds($before->outcome('pass')))->toBe([1, 3, 5])
        ->and(predicateIds(P::all($before->outcome('pass'), P::policyResult(null)->outcome('allow'))))->toBe([])
        ->and(predicateIds(P::beforeResult(BeforeResult::Deny)->outcome('pass')))->toBe([]);
});

it('ORs complete typed contribution branches without mixing witness conditions', function (): void {
    $first = Grant::of(PermissionPattern::of('admin', 'orders.view'), 'database', AccessScope::in(TenantRef::global()), fields: ['city' => 'Paris', 'minimum' => 20]);
    $second = Grant::of(PermissionPattern::of('admin', 'orders.view'), 'database', AccessScope::in(TenantRef::global()), fields: ['city' => 'Rome', 'minimum' => 30]);
    $branch = fn (Grant $grant): P => P::branch($grant, P::all(P::eq('city', $grant->fields()['city']), P::gte('score', $grant->fields()['minimum'])));
    expect(predicateIds(P::any($branch($first), $branch($second))))->toBe([4])
        ->and(predicateIds(P::all(P::eq('tenant_id', 1), P::any($branch($first), $branch($second)))))->toBe([]);
});
