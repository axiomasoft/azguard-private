<?php

declare(strict_types=1);

use AzGuard\Context\AssignmentScopeDeclaration;
use AzGuard\Context\AssignmentScopeMode;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
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
    $configure = static fn (PanelBuilder $p) => $p->plugins([ScopesPlugin::inherit((new VisibilityProjectScope)
        ->filter(static fn (Builder $query) => $query->where('city', 'Paris')))->accessAdapter('project', $adapter)]);
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(2), W::grant()]), $configure);

    // Project 2 is in Rome: (city = Paris) AND (id > 0 OR city = Rome) excludes it in its own scope and in the list of Paris projects.
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view', W::scope(2))->pluck('id')->all())->toBe([])
        ->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 4]);
});

it('raises scope_query for every false answer of a queryable evaluator, also for a global request', function (): void {
    $evaluator = new class implements QueryableAssignmentScopeEvaluator
    {
        public function validate(AssignmentScopeDefinition $definition, ?AssignmentScopeDefinition $registered): void {}

        public function allows(AssignmentScopeRuntime $runtime, ResolvedAssignmentScope $resolved): bool
        {
            return true;
        }

        public function allowsMany(array $checks): array
        {
            return array_fill(0, count($checks), true);
        }

        public function eligibleContexts(AssignmentScopeRuntime $runtime, QueryableAssignmentScopeDefinition $definition, Builder $contexts, Builder $universe): bool
        {
            return ! $runtime->scope->context->isGlobal();
        }
    };
    app()->instance($evaluator::class, $evaluator);

    expect(scopeQueryReason($evaluator::class))->toBe('scope_query');
});

it('budgets the contexts of the owner only, not every row of the type', function (): void {
    $configure = static fn (PanelBuilder $p) => $p->plugins([ScopesPlugin::inherit((new VisibilityProjectScope)
        ->filter(static fn (Builder $query) => $query->where('city', 'Paris')))]);
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
