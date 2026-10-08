<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Query\PredicateCompiler;
use AzGuard\Authorization\Query\VisibilityScope;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Grammar\PatternMatcher;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\NativeGateBinding;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\PanelSources;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

/**
 * Exact final access before aggregation or pagination. Bounded fallback checks the whole host universe.
 *
 * @api
 */
final readonly class Visibility
{
    public function __construct(private PanelRegistry $registry, private ModelSubjectResolver $subjects, private Container $container, private CurrentContext $current) {}

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visibleTo(Panel $panel, Builder $query, Model|SubjectRef|null $subject, UnitEnum|string $permission, ?AccessScope $scope = null): Builder
    {
        if ($subject === null) {
            try {
                return (new PredicateCompiler)->constrain($query, P::deny());
            } catch (Throwable $error) {
                throw new VisibilityNotSupportedException('unsafe_host_query', $error::class);
            }
        }
        $now = Carbon::now('UTC')->toDateTimeImmutable();
        for ($attempt = 0; ; $attempt++) {
            try {
                $candidate = $this->build($panel, clone $query, $subject, $permission, $scope, $now);
                $query->setQuery($candidate->getQuery())->setEagerLoads($candidate->getEagerLoads())->withoutGlobalScopes();

                return $query;
            } catch (ReadAttemptChanged) {
                if ($attempt === 2) {
                    throw new VisibilityNotSupportedException('consistency_error', 'sources');
                }
            } catch (VisibilityNotSupportedException|ConflictingPanelException|SubjectNotAcceptedException $error) {
                throw $error;
            } catch (Throwable $error) {
                throw new VisibilityNotSupportedException($error instanceof ConsistencyException ? 'consistency_error' : 'source_error', $error::class);
            }
        }
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function build(Panel $panel, Builder $query, Model|SubjectRef $subject, UnitEnum|string $permission, ?AccessScope $scope, DateTimeImmutable $now): Builder
    {
        [$request, $frame, $catalog] = $this->inputs($panel, $subject, $permission, $scope, $now);
        $sourceRead = new ReadAttempt($catalog, PanelSources::of($this->registry->recipe($panel->id()), $this->container)->all(), $frame, publish: false);

        if (! $catalog->has($request->permission()) && $catalog->isDynamic()) {
            $catalog = $sourceRead->catalog();
        }
        $definition = $catalog->get($request->permission());
        $compiler = new PredicateCompiler;
        $mapping = $this->mapping($query, $frame, $compiler);
        $type = $mapping?->definition->type() ?? 'global';
        $contributions = $definition->authority === PermissionAuthority::Grants ? $sourceRead->selections($request, $frame, $type) : [];
        $frame = $sourceRead->consumedFrame($frame);
        $compiler->constrain($query, P::pass());
        // Assemble on a detached group so an unsupported sibling never mutates the caller.
        $group = $query->getModel()->newModelQuery();

        if ($mapping !== null) {
            $mapping->constrain($group, $mapping->query($request, $frame, common: true));
        }
        // Tenant membership is one fixed subject/tenant input for the entire operation.
        $declared = $panel->tenants()->membership();

        if ($declared !== null && ! $frame->scope()->tenant->isGlobal()) {
            $member = is_string($declared) ? $this->container->make($declared) : $declared;

            if (! $member->isMember($request->subject(), $frame->scope()->tenant)) {
                $group->whereRaw('1 = 0');
            }
        }
        foreach ($panel->before() as $declared) {
            $hook = is_string($declared) ? $this->container->make($declared) : $declared;
            $predicate = $this->predicate($hook, $request, $frame, $query->getModel()::class);
            $predicate->assertPartition(before: true);
            $compiler->constrain($group, $predicate->outcome('pass'));
        }
        $binding = $catalog->policyBindings()[$request->permission()->local()] ?? null;
        $policy = P::policyResult(null);

        if ($binding !== null) {
            $component = $binding->kind === 'gate'
                ? $this->container->make(NativeGateBinding::class)->resolve($binding)['policy']
                : $this->container->make($binding->policy ?? throw new VisibilityNotSupportedException('policy_binding'));
            $policy = $this->predicate($component, $request, $frame, $query->getModel()::class);
            $policy->assertPartition();
        }
        $authority = $query->getModel()->newModelQuery();

        if ($definition->authority === PermissionAuthority::Policy) {
            $compiler->constrain($authority, $policy->outcome('allow'));
            $this->membership($authority, $request, $frame, $mapping, $compiler);
            $this->restrictions($authority, $request, $frame, $compiler);
        } else {
            $branches = [];
            foreach ($contributions as [$source, $item]) {
                if (! $item->activeAt($now)) {
                    continue;
                }
                $roleDefinition = $item->role === null ? null : ($catalog->roles()[$item->role->key()] ?? null);

                if ($item->role !== null && ($roleDefinition === null || ! $roleDefinition['grantable'])) {
                    continue;
                }

                if (! $item->scope->tenant->equals($frame->scope()->tenant)
                    && ($roleDefinition === null || ! in_array($roleDefinition['class'], $panel->tenants()->globalRoles(), true))) {
                    continue;
                }
                $ref = $item->scope->context;
                $fixed = $source instanceof FolderSource && $item instanceof Grant && $item->origin === 'granted_to_all';

                if ($ref->isGlobal() && $mapping !== null && $panel->scopes()->mode() === 'isolated' && ! $fixed) {
                    continue;
                }

                if (! $ref->isGlobal() && (! $frame->scope()->context->isGlobal() && ! $ref->equals($frame->scope()->context))) {
                    continue;
                }

                if ($roleDefinition !== null && ($ref->isGlobal() ? $roleDefinition['scope_required'] : ! in_array($ref->type(), array_column($roleDefinition['scopes'], 'type'), true))) {
                    continue;
                }
                $admin = $item instanceof RoleContribution && ($roleDefinition['super_admin'] ?? false);
                $covers = $item instanceof Grant ? PatternMatcher::covers($item->pattern->local(), $request->permission()->local())
                    : array_filter($roleDefinition['permissions'] ?? [], static fn (string $pattern): bool => PatternMatcher::covers($pattern, $request->permission()->local())) !== [];
                // Conditions and role eligibility qualify each live contribution before permission matching.
                $role = $roleDefinition === null ? null : $this->container->make($roleDefinition['class']);
                $branchFrame = ($ref->isGlobal() ? $frame : $frame->withScope(AccessScope::in($frame->scope()->tenant, $ref)))->forContribution($item, $role);
                $branch = $query->getModel()->newModelQuery();

                if ($mapping !== null) {
                    $mapping->constrain($branch, $mapping->query($request, $branchFrame));
                } elseif (! $ref->isGlobal()) {
                    throw new VisibilityNotSupportedException('unexpected_scoped_witness');
                }
                $conditions = [];
                foreach ($panel->grantConditions() as $declared) {
                    $condition = is_string($declared) ? $this->container->make($declared) : $declared;
                    $conditions[] = $this->predicate($condition, $request, $branchFrame, $query->getModel()::class, $item);
                }
                $compiler->constrain($branch, P::branch($item, P::all(...$conditions)));

                if (! $admin && ! $covers) {
                    continue;
                }
                $qualified = $branchFrame->withAuthority([], $admin);
                $this->membership($branch, $request, $qualified, $mapping, $compiler);
                $this->restrictions($branch, $request, $qualified, $compiler);
                $branches[] = $branch;
            }
            $this->orBranches($authority->getQuery(), array_map(static fn (Builder $branch): QueryBuilder => $branch->getQuery(), $branches));
            $compiler->constrain($authority, P::not($policy->outcome('deny')));
        }
        $group->getQuery()->addNestedWhereQuery($authority->getQuery());
        $sourceRead->confirm($frame);
        $query->getQuery()->addNestedWhereQuery($group->getQuery());

        // Context relations also contribute native constraints after predicate compilation.
        return $compiler->constrain($query, P::pass());
    }

    /** @param list<QueryBuilder> $branches */
    private function orBranches(QueryBuilder $query, array $branches): void
    {
        $or = $query->forNestedWhere();

        if ($branches === []) {
            $or->whereRaw('1 = 0');
        } elseif (count($branches) <= 16) {
            foreach ($branches as $branch) {
                $or->addNestedWhereQuery($branch, 'or');
            }
        } else {
            $halves = [array_slice($branches, 0, intdiv(count($branches), 2)), array_slice($branches, intdiv(count($branches), 2))];
            foreach ($halves as $chunk) {
                $group = $query->forNestedWhere();
                $this->orBranches($group, $chunk);
                $or->addNestedWhereQuery($group, 'or');
            }
        }
        $query->addNestedWhereQuery($or);
    }

    /** @return array{AccessRequest, EvaluationFrame, PanelCatalog} */
    private function inputs(Panel $panel, Model|SubjectRef $subject, UnitEnum|string $permission, ?AccessScope $scope, DateTimeImmutable $now): array
    {
        $ref = $subject instanceof Model ? SubjectRef::of($subject->getMorphClass(), $subject->getKey()) : $subject;

        if (! $panel->accepts($ref)) {
            throw new SubjectNotAcceptedException('Selected panel does not accept this subject.');
        }
        $catalog = $this->registry->catalog($panel->id());
        $key = $permission instanceof UnitEnum ? $catalog->keyOf($permission) : (str_contains($permission, ':') ? PermissionKey::parse($permission) : PermissionKey::of($panel->id(), $permission));

        if ($key->panel() !== $panel->id()) {
            throw new ConflictingPanelException('Visibility permission belongs to another panel.');
        }
        $scope ??= $this->current->get($panel) ?? AccessScope::in(TenantRef::global());

        if (($panel->tenants()->mode() === 'required' && ($scope->tenant->isGlobal() || $scope->tenant->type() !== $panel->tenants()->definition()?->type()))
            || ($panel->tenants()->mode() === 'none' && ! $scope->tenant->isGlobal())
            || (! $scope->context->isGlobal() && ($panel->scopes()->mode() === 'none' || $panel->scopeDefinition($scope->context->type() ?? '') === null))) {
            throw new VisibilityNotSupportedException('selected_scope');
        }
        $model = $this->subjects->resolve($panel, $ref);
        $frame = new EvaluationFrame($panel, $scope, CodeStateToken::of($panel->id(), $this->registry->buildId(), $this->registry->fingerprint($panel->id())), $now,
            ActorRef::of($ref->type(), $ref->id()), $model, $model);

        return [AccessRequest::for($ref, $key)->inScope($scope), $frame, $catalog];
    }

    /** @param Builder<*> $query */
    private function mapping(Builder $query, EvaluationFrame $frame, PredicateCompiler $compiler): ?VisibilityScope
    {
        $panel = $frame->panel();
        $model = $query->getModel();

        if ($panel->scopes()->mode() === 'none') {
            if ($panel->tenants()->mode() !== 'none' || $model instanceof ProvidesAccessScope || $model instanceof ProvidesAssignmentScope || $panel->resourceScopes() !== []) {
                throw new VisibilityNotSupportedException('resource_owner_adapter');
            }

            return null;
        }

        if (! method_exists($model, 'azguardContextType') || ! method_exists($model, 'azguardContextRelation')
            || ($panel->tenants()->mode() === 'required' ? ! $model instanceof ProvidesAccessScope : ! $model instanceof ProvidesAssignmentScope)
            || $panel->resourceScopes() !== []) {
            throw new VisibilityNotSupportedException('resource_context_mapping');
        }
        $type = $model->azguardContextType();
        $definition = $panel->scopeDefinition($type);

        if (! $definition instanceof QueryableAssignmentScopeDefinition
            || (! $frame->scope()->context->isGlobal() && $frame->scope()->context->type() !== $type)) {
            throw new VisibilityNotSupportedException('resource_context_type');
        }

        return new VisibilityScope($query, $definition, $model->azguardContextRelation(), $this->container, $compiler);
    }

    private function predicate(mixed $component, AccessRequest $request, EvaluationFrame $frame, string $type, Grant|RoleContribution|null $contribution = null): P
    {
        if (! $component instanceof FiltersAccessQueries) {
            throw new VisibilityNotSupportedException('missing_exact_adapter', is_object($component) ? $component::class : null);
        }
        $predicate = $component->predicate($request, $type, $frame, $contribution);

        if (! $predicate->isSupported()) {
            throw new VisibilityNotSupportedException('unsupported_predicate', $component::class);
        }

        if ($contribution !== null) {
            $predicate->assertContribution($contribution);
        }

        return $predicate;
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     */
    private function restrictions(Builder $query, AccessRequest $request, EvaluationFrame $frame, PredicateCompiler $compiler): void
    {
        $keys = [];
        foreach ($frame->panel()->restrictions() as $declared) {
            $restriction = is_string($declared) ? $this->container->make($declared) : $declared;
            $key = $restriction->key();

            if (isset($keys[$key])) {
                throw new VisibilityNotSupportedException('duplicate_restriction');
            }
            $keys[$key] = true;

            if (($frame->qualifiedSuperAdmin && $restriction->exemptsSuperAdmin()) || (! $restriction instanceof FiltersAccessQueries && ! $restriction->appliesTo($request, $frame))) {
                continue;
            }
            $compiler->constrain($query, $this->predicate($restriction, $request, $frame, $query->getModel()::class));
        }
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     */
    private function membership(Builder $query, AccessRequest $request, EvaluationFrame $frame, ?VisibilityScope $mapping, PredicateCompiler $compiler): void
    {
        if ($frame->panel()->scopes()->membership() !== null && $mapping !== null) {
            $declared = $frame->panel()->scopes()->membership();
            $member = is_string($declared) ? $this->container->make($declared) : $declared;
            $compiler->constrain($query, $this->predicate($member, $request, $frame, $query->getModel()::class));
        }
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public function bounded(Panel $panel, Builder $query, Model|SubjectRef|null $subject, UnitEnum|string $permission, ?AccessScope $scope = null, int $limit = 1000, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        if ($limit < 1 || $perPage < 1 || $page < 1) {
            throw new VisibilityNotSupportedException('invalid_bounds');
        }

        if ($subject === null) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }
        $now = Carbon::now('UTC')->toDateTimeImmutable();
        [$request, $frame] = $this->inputs($panel, $subject, $permission, $scope, $now);
        $candidate = (new PredicateCompiler)->constrain(clone $query, P::pass());

        if ($candidate->getQuery()->limit !== null || $candidate->getQuery()->offset !== null
            || $candidate->getQuery()->groups !== null || $candidate->getQuery()->havings !== null
            || $candidate->getQuery()->columns !== null) {
            throw new VisibilityNotSupportedException('incomplete_host_universe');
        }

        // Narrow only structural ownership, never assignments or arbitrary authority candidates.
        if (method_exists($candidate->getModel(), 'azguardContextType')) {
            $mapping = $this->mapping($candidate, $frame, new PredicateCompiler);

            if ($mapping !== null) {
                $mapping->constrain($candidate, $mapping->query($request, $frame, eligibility: false));
            }
        }
        $records = $candidate->limit($limit + 1)->get();

        if ($records->count() > $limit) {
            throw new VisibilityNotSupportedException('bounded_universe_limit');
        }
        $authorizer = $this->container->make(Authorizer::class);
        $context = $request->context();
        $allowed = [];
        foreach ($records as $record) {
            if ($authorizer->decideAt($panel, $request->on($context?->isGlobal() ? null : $context, $record), $now)->allowed()) {
                $allowed[] = $record;
            }
        }

        return new LengthAwarePaginator(array_slice($allowed, ($page - 1) * $perPage, $perPage), count($allowed), $perPage, $page);
    }
}
