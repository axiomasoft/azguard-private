<?php

declare(strict_types=1);

namespace AzGuard\Sources\Relation;

use AzGuard\Catalog\PanelCatalog;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\FiltersQueries;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Raw roles from membership on a host entity. The engine expands and qualifies the roles.
 *
 * @api
 */
final class RelationSource implements DescribesSchema, FiltersQueries, ProvidesRoleGrants
{
    private ?RelationBinding $binding = null;

    private ?string $panelId = null;

    private function __construct(
        private readonly string|AssignmentScopeDefinition $model,
        private readonly string $via,
        private readonly string|Closure $role,
        private readonly ?Closure $scope,
    ) {}

    /** @param string|AssignmentScopeDefinition $model a model class registered by a panel scope, or its descriptor
     * @param  string|Closure(Model): ?string  $role  static role, pivot.role or callback receiving the pivot/related model
     * @param  (Closure(Builder<Model>): mixed)|null  $scope  grouped narrowing of the related-root query
     */
    public static function make(string|AssignmentScopeDefinition $model, string $via, string|Closure $role, ?Closure $scope = null): self
    {
        return new self($model, $via, $role, $scope);
    }

    /** @internal Normalizes the model-class shortcut from existing panel scope definitions.
     * @param  iterable<AssignmentScopeDefinition>  $definitions
     */
    public function bind(string $panel, iterable $definitions = []): void
    {
        if ($this->panelId !== null && $this->panelId !== $panel) {
            throw new DefinitionException('A RelationSource instance belongs to one panel; create a separate source for each panel.');
        }

        $definition = $this->model instanceof AssignmentScopeDefinition ? $this->model : null;

        if (! $definition instanceof AssignmentScopeDefinition) {
            foreach ($definitions as $candidate) {
                if ($candidate->model() !== $this->model) {
                    continue;
                }

                if ($definition !== null && ($definition->type() !== $candidate->type() || $definition::class !== $candidate::class)) {
                    throw new DefinitionException('RelationSource model '.$this->model.' has multiple panel scope definitions; pass the intended AssignmentScopeDefinition explicitly.');
                }
                $definition = $candidate;
            }
        }

        if ($definition === null) {
            throw new DefinitionException('RelationSource model '.get_debug_type($this->model).' has no registered panel assignment-scope definition; discover its descriptor, declare it in a role scopes(), or pass a QueryableAssignmentScopeDefinition explicitly.');
        }

        $this->binding = RelationBinding::make($definition, $this->via, $this->role === 'pivot.role');
        $this->panelId = $panel;
    }

    /** @internal Validates against the final catalog, including a restored catalog cache. */
    public function validateCatalog(PanelCatalog $catalog): void
    {
        if (! is_string($this->role) || $this->role === 'pivot.role') {
            return;
        }

        try {
            RoleKey::of($catalog->panel(), $this->role);
        } catch (Throwable $error) {
            throw new DefinitionException('RelationSource "'.$this->id().'" needs a valid static role key; got '.json_encode($this->role).'.', previous: $error);
        }

        if (! isset($catalog->roles()[$this->role])) {
            throw new UnknownRoleException('RelationSource "'.$this->id().'" names the unknown static role "'.$this->role.'" in panel "'.$catalog->panel().'"; declare this PHP role in the panel or use a membership role callback.');
        }
    }

    public function id(): string
    {
        return $this->binding->id ?? ($this->model instanceof AssignmentScopeDefinition
            ? RelationBinding::identity($this->model->type())
            : throw new DefinitionException('Attach RelationSource model '.$this->model.' to its panel before reading the normalized source id.'));
    }

    public function volatility(): Volatility
    {
        return Volatility::Request;
    }

    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription
    {
        return new SourceDescription($this->id(), self::class, [ProvidesRoleGrants::class, FiltersQueries::class, DescribesSchema::class], false, label: 'Relation');
    }

    /** @param list<AccessScope> $scopes
     * @return list<RoleContribution>
     */
    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $binding = $this->bound($context->panel());
        $requested = $resolved = [];
        foreach ($scopes as $scope) {
            if ($scope->context->isGlobal() || $scope->context->type() !== $binding->definition->type()) {
                continue;
            }
            $requested[$scope->context->key()][] = $scope;
        }

        // A global scalar check never enumerates entity memberships.
        if ($requested === [] || ! $this->acceptsSubject($binding, $subject)) {
            return [];
        }

        foreach ($requested as $key => $pairs) {
            $scope = $pairs[0];
            $owner = $binding->definition->resolve($scope->context);

            if (! $owner instanceof ResolvedAssignmentScope) {
                continue;
            }

            if (! $owner->ref->equals($scope->context)) {
                throw new InvalidSourceContributionException('RelationSource descriptor resolved a different assignment-scope reference.');
            }

            if ($owner->record instanceof Model && (! $owner->record instanceof $binding->model
                || (! is_int($owner->record->getKey()) && ! is_string($owner->record->getKey()))
                || IdentityCodec::canonicalId($owner->record->getKey()) !== $scope->context->id())) {
                throw new InvalidSourceContributionException('RelationSource descriptor resolved a record with a different model or key.');
            }
            foreach ($pairs as $pair) {
                if ($pair->tenant->equals($owner->tenant)) {
                    $resolved[$key] = AccessScope::in($owner->tenant, $owner->ref);

                    break;
                }
            }
        }

        if ($resolved === []) {
            return [];
        }

        $query = $this->query($binding, $subject);
        $query->whereKey(array_map(static fn (AccessScope $scope): ?string => $scope->context->id(), array_values($resolved)));

        return $this->contributions($binding, $query, $subject, $context, $resolved);
    }

    public function contextsCovering(SubjectRef $subject, PermissionKey $key, string $contextType, EvaluationContext $context): AssignmentScopeSelection
    {
        IdentityCodec::assertTypeAlias($contextType);

        if ($key->panel() !== $context->panel()->id()) {
            throw new InvalidSourceContributionException('RelationSource selection permission belongs to another panel.');
        }
        $binding = $this->bound($context->panel());

        if ($contextType !== $binding->definition->type() || ! $this->acceptsSubject($binding, $subject)) {
            return AssignmentScopeSelection::nowhere();
        }

        $contributions = $this->contributions($binding, $this->query($binding, $subject), $subject, $context, null);
        $refs = [];
        foreach ($contributions as $contribution) {
            $refs[$contribution->scope->context->key()] = $contribution->scope->context;
        }

        return AssignmentScopeSelection::in(array_values($refs), $contributions);
    }

    private function bound(Panel $panel): RelationBinding
    {
        if (! $this->binding instanceof RelationBinding) {
            $this->bind($panel->id());
        }

        if ($this->panelId !== $panel->id()) {
            throw new InvalidSourceContributionException('RelationSource was read for a different panel.');
        }

        return $this->binding ?? throw new DefinitionException('RelationSource must be bound before reading memberships.');
    }

    private function acceptsSubject(RelationBinding $binding, SubjectRef $subject): bool
    {
        return $binding->relation()->getRelated()->getMorphClass() === $subject->type();
    }

    /** @return Builder<Model> */
    private function query(RelationBinding $binding, SubjectRef $subject): Builder
    {
        $query = clone $binding->definition->query();

        if ($query->getModel()::class !== $binding->model) {
            throw new InvalidSourceContributionException('RelationSource descriptor query no longer targets its declared model.');
        }

        $query->whereHas($binding->via, static function (Builder $membership) use ($subject): void {
            $membership->whereKey($subject->id());
        });

        if ($this->scope instanceof Closure) {
            $narrow = RelationScopeQuery::forPredicates($query);
            $before = RelationScopeQuery::structure($narrow);
            ($this->scope)($narrow);

            if (RelationScopeQuery::structure($narrow) !== $before) {
                throw new InvalidSourceContributionException('RelationSource scope callback may only narrow grouped WHERE predicates; it cannot change the query structure.');
            }
            $query->getQuery()->addNestedWhereQuery($narrow->getQuery());
        }

        return $query;
    }

    /** @param Builder<Model> $query
     * @param  array<string, AccessScope>|null  $requested  null enumerates the selected tenant
     * @return list<RoleContribution>
     */
    private function contributions(RelationBinding $binding, Builder $query, SubjectRef $subject, EvaluationContext $context, ?array $requested): array
    {
        // Bound roots before hydration/eager loading, including foreign-tenant and null-role rows.
        // A descriptor may narrow this further; exceeding the budget refuses the whole selection.
        $limit = $query->getQuery()->limit;
        $query->limit($limit === null ? 10001 : min($limit, 10001));
        $query->setEagerLoads([]);
        $records = $query->get();

        if ($records->count() > 10000) {
            throw new InvalidSourceContributionException('RelationSource exceeds the 10000 related-root selection budget.');
        }
        $records->load([$binding->via => function (Relation $membership) use ($subject): void {
            $membership->whereKey($subject->id());
            $membership->getQuery()->setEagerLoads([]);
            $membership->getQuery()->limit(10001);

            if ($membership instanceof BelongsToMany && $this->role === 'pivot.role') {
                $membership->withPivot('role');
            }
        }]);

        $memberCount = 0;
        foreach ($records as $record) {
            $loaded = $record->getRelation($binding->via);
            $memberCount += $loaded instanceof Collection ? $loaded->count() : ($loaded === null ? 0 : 1);
        }

        if ($memberCount > 10000) {
            throw new InvalidSourceContributionException('RelationSource exceeds the 10000 membership selection budget.');
        }
        $items = [];
        foreach ($records as $record) {
            $id = $record->getKey();

            if (! is_int($id) && ! is_string($id)) {
                throw new InvalidSourceContributionException('RelationSource entity needs an integer or string key.');
            }
            $ref = AssignmentScopeRef::of($binding->definition->type(), $id);

            if ($requested !== null) {
                $scope = $requested[$ref->key()] ?? null;

                if ($scope === null) {
                    continue;
                }
            } else {
                $tenant = $binding->definition->tenantOf($record);

                if (! $tenant->equals($context->scope()->tenant)) {
                    continue;
                }
                $scope = AccessScope::in($tenant, $ref);
            }

            $loaded = $record->getRelation($binding->via);
            $members = $loaded instanceof Collection ? $loaded->all() : ($loaded === null ? [] : [$loaded]);
            foreach ($members as $member) {
                if (! $member instanceof Model || $member->getMorphClass() !== $subject->type()
                    || (! is_int($member->getKey()) && ! is_string($member->getKey()))
                    || IdentityCodec::canonicalId($member->getKey()) !== $subject->id()) {
                    throw new InvalidSourceContributionException('RelationSource eager membership differs from the requested subject.');
                }
                $role = $this->membershipRole($binding, $member);

                if ($role === null) {
                    continue;
                }

                if (count($items) >= 10000) {
                    throw new InvalidSourceContributionException('RelationSource exceeds the 10000 assignment witness budget.');
                }
                $items[] = RoleContribution::of(RoleKey::of($context->panel()->id(), $role), $scope, $binding->id, 'relation');
            }
        }

        return $items;
    }

    private function membershipRole(RelationBinding $binding, Model $member): ?string
    {
        $value = $member;

        if ($binding->pivotAccessor !== null) {
            $value = $member->getRelation($binding->pivotAccessor);

            if (! $value instanceof Model) {
                throw new InvalidSourceContributionException('RelationSource membership is missing its eager-loaded pivot.');
            }
        }
        $role = $this->role instanceof Closure ? ($this->role)($value)
            : ($this->role === 'pivot.role' ? $value->getAttribute('role') : $this->role);

        if ($role !== null && ! is_string($role)) {
            throw new InvalidSourceContributionException('RelationSource role callback or pivot.role must return a string role key or null.');
        }

        return $role;
    }
}
