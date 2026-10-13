<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Query\ResourceContextMapping;
use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeEvaluator;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\ContextAware;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tests\Fixtures\Scopes\RecordingEvaluator;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProjectScope;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

beforeEach(function (): void {
    W::seed();
    RecordingEvaluator::reset();
});
afterEach(function (): void {
    W::reset();
    RecordingEvaluator::reset();
});

/** The reason visibleTo gives on the client list of a panel whose own scope declaration replaces the world's. */
function scopeQueryReason(?string $evaluator, ?Model $model = null): ?string
{
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]), static fn (PanelBuilder $p) => $p
        ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new VisibilityProjectScope], evaluator: $evaluator)), mode: 'none');

    try {
        $visibility->visibleTo($panel, ($model ?? new VisibilityClient)->newQuery(), W::subject(), 'orders.view');
    } catch (VisibilityNotSupportedException $error) {
        return $error->reason;
    }

    return null;
}

it('lists scoped resources only through a queryable evaluator and never approximates without one', function (): void {
    // Without any evaluator the role filters of the world are refused at compilation (nobody would apply them).
    expect(fn () => scopeQueryReason(null))->toThrow(DefinitionException::class, 'without an evaluator that applies them')
        ->and(scopeQueryReason(RecordingEvaluator::class))->toBe('scope_query')
        ->and(RecordingEvaluator::$asked)->toBe([]);
});

it('maps a resource to its context only through MapsAssignmentScope, not by method names', function (): void {
    $unmapped = new class extends Model implements ProvidesAccessScope, ProvidesAssignmentScope
    {
        use ContextAware;

        protected $table = 'visibility_clients';

        public function azguardContextType(): string
        {
            return 'project';
        }

        public function azguardContextRelation(): ?string
        {
            return 'project';
        }

        /** @return BelongsTo<VisibilityProject, $this> */
        public function project(): BelongsTo
        {
            return $this->belongsTo(VisibilityProject::class, 'project_id');
        }

        public function azguardScope(): AccessScope
        {
            return AccessScope::in(TenantRef::global());
        }
    };
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1)]));

    expect(fn () => $visibility->visibleTo($panel, $unmapped->newQuery(), W::subject(), 'orders.view'))
        ->toThrow(VisibilityNotSupportedException::class, 'resource_context_mapping')
        ->and($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->pluck('id')->all())->not->toBe([]);
});

it('marks witnesses of every context only when they are tenant-wide', function (): void {
    $global = W::grant();
    $scoped = W::grant(1);

    expect(AssignmentScopeSelection::inEveryContext([$global])->appliesInEveryContext())->toBeTrue()
        ->and(AssignmentScopeSelection::inEveryContext([$global])->isEverywhere())->toBeTrue()
        ->and(AssignmentScopeSelection::everywhere([$global])->appliesInEveryContext())->toBeFalse()
        ->and(fn () => AssignmentScopeSelection::inEveryContext([$scoped]))->toThrow(InvalidSourceContributionException::class, 'tenant-wide');
});

it('keeps an OR inside the access adapter conjoined with the definition filters (no broadening)', function (): void {
    $adapter = new class implements AssignmentScopeAccessAdapter
    {
        public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
        {
            return true;
        }

        public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
        {
            return array_fill_keys(array_map(static fn (AssignmentScopeRef $ref): string => $ref->key(), $refs), true);
        }

        public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
        {
            return $contextQuery->where('id', '>', 0)->orWhere('city', 'Rome');
        }
    };
    W::$projectScope = (new VisibilityProjectScope)->filter(static fn (Builder $query) => $query->where('city', 'Paris'));
    $configure = static fn (PanelBuilder $p) => $p->plugins([ScopesPlugin::inherit(W::$projectScope)->accessAdapter('project', $adapter)]);
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(2), W::grant()]), $configure);

    // Project 2 is in Rome: (city = Paris) AND (id > 0 OR city = Rome) excludes it in its own scope and in the list of Paris projects.
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view', W::scope(2))->pluck('id')->all())->toBe([])
        ->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 4]);
});

/**
 * A queryable evaluator that answers `$global` for a global runtime and, for a concrete one, narrows to Paris
 * (`$concrete` true) or refuses; it keeps the contexts it was asked about.
 */
function specializingEvaluator(bool $global, bool $concrete): QueryableAssignmentScopeEvaluator
{
    return new class($global, $concrete) implements QueryableAssignmentScopeEvaluator
    {
        /** @var list<string|null> */
        public array $asked = [];

        public function __construct(private readonly bool $global, private readonly bool $concrete) {}

        public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void {}

        public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
        {
            return true;
        }

        public function allowsMany(array $checks): array
        {
            return array_fill(0, count($checks), true);
        }

        public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition, Builder $contexts): bool
        {
            $this->asked[] = $runtime->scope->context->id();

            if ($runtime->scope->context->isGlobal()) {
                return $this->global;
            }
            $contexts->where('city', 'Paris');

            return $this->concrete;
        }
    };
}

/** @return list<int> ids of the projects a global grant of tenant A lists through `$evaluator` */
function specializedList(QueryableAssignmentScopeEvaluator $evaluator): array
{
    app()->instance($evaluator::class, $evaluator);
    W::$tenanted = true;
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]), static fn (PanelBuilder $p) => $p
        ->assignmentScopes(new AssignmentScopeDeclaration(AssignmentScopeMode::Inherit, [new VisibilityProjectScope], evaluator: $evaluator::class)), mode: 'none', tenant: true);

    return $visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view', W::scope())->orderBy('id')->pluck('id')->all();
}

it('raises scope_query when a queryable evaluator refuses a concrete context', function (): void {
    $evaluator = specializingEvaluator(global: false, concrete: false);
    app()->instance($evaluator::class, $evaluator);

    expect(scopeQueryReason($evaluator::class))->toBe('scope_query')
        ->and(fn () => specializedList($evaluator))->toThrow(VisibilityNotSupportedException::class, 'scope_query');
});

it('asks again once per owner context, with its concrete runtime, when the evaluator refuses a global runtime', function (): void {
    $evaluator = specializingEvaluator(global: false, concrete: true);

    // Tenant A owns projects 1–3; only project 1 is in Paris. Project 4 (Paris, tenant B) is never asked about.
    expect(specializedList($evaluator))->toBe([1])
        ->and(array_values(array_unique($evaluator->asked)))->toEqualCanonicalizing([null, '1', '2', '3']);
});

it('does not specialize a global runtime the evaluator expresses at once', function (): void {
    $evaluator = specializingEvaluator(global: true, concrete: true);

    expect(specializedList($evaluator))->toBe([1, 2, 3])->and(array_values(array_unique($evaluator->asked)))->toBe([null]);
});

it('refuses a global list that would expand into more owner contexts than the budget', function (): void {
    $owned = [];

    for ($id = 5; $id <= 5 + ResourceContextMapping::CONTEXT_BUDGET; $id++) {
        $owned[] = ['id' => $id, 'project_id' => null, 'org' => 'A', 'city' => 'Rome', 'state' => null, 'active' => true];
    }

    foreach (array_chunk($owned, 200) as $chunk) {
        VisibilityProject::query()->insert($chunk);
    }

    expect(fn () => specializedList(specializingEvaluator(global: false, concrete: true)))
        ->toThrow(VisibilityNotSupportedException::class, 'scope_runtime_budget');
});

it('budgets the contexts of the owner only, not every row of the type', function (): void {
    W::$projectScope = (new VisibilityProjectScope)->filter(static fn (Builder $query) => $query->where('city', 'Paris'));
    $configure = static fn (PanelBuilder $p) => $p->plugins([ScopesPlugin::inherit(W::$projectScope)]);
    W::$tenanted = true;
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]), $configure, tenant: true);
    $foreign = [];

    for ($id = 5; $id <= 1005; $id++) {
        $foreign[] = ['id' => $id, 'project_id' => null, 'org' => 'B', 'city' => 'Rome', 'state' => null, 'active' => true];
    }

    foreach (array_chunk($foreign, 200) as $chunk) {
        VisibilityProject::query()->insert($chunk);
    }

    // 1005 rows of the type, but tenant A owns three of them: the list stays exact, as on 0.7.0.
    expect($visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view', W::scope())->pluck('id')->all())->toBe([101]);
});

it('makes a class-string access adapter anew for every context of a global list', function (): void {
    ScopeQueryStatefulAdapter::$calls = 0;
    $configure = static fn (PanelBuilder $p) => $p->plugins([ScopesPlugin::inherit(new VisibilityProjectScope)->accessAdapter('project', ScopeQueryStatefulAdapter::class)]);
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant()]), $configure);

    // The first adapter narrows to Paris and every later one does not: a shared instance would narrow all contexts.
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 2, 3, 4]);
});

final class ScopeQueryStatefulAdapter implements AssignmentScopeAccessAdapter
{
    public static int $calls = 0;

    private bool $first;

    public function __construct()
    {
        $this->first = self::$calls++ === 0;
    }

    public function allows(AssignmentScopeRef $ref, AssignmentScopeRuntime $runtime): bool
    {
        return true;
    }

    public function allowsMany(array $refs, AssignmentScopeRuntime $runtime): array
    {
        return array_fill_keys(array_map(static fn (AssignmentScopeRef $ref): string => $ref->key(), $refs), true);
    }

    public function constrain(Builder $contextQuery, AssignmentScopeRuntime $runtime): Builder
    {
        return $this->first ? $contextQuery->where('city', 'Paris') : $contextQuery->whereRaw('1 = 1');
    }
}
