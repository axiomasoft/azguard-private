<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Internal;

use AzGuard\Context\AssignmentScopePhase;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Directories\AssignmentScopeOption;
use AzGuard\Directories\LookupContext;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DirectoryScanLimitException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\Contracts\AssignmentScopeAccessAdapter;
use AzGuard\Scopes\Contracts\AssignmentScopeMembership;
use AzGuard\Scopes\Contracts\ConfigurableAssignmentScopeDefinition;
use AzGuard\Scopes\Internal\Query\EligibilityBuilder;
use AzGuard\Support\ModelKey;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use ReflectionMethod;

/**
 * Default directory of an assignment scope backed by an Eloquent query.
 *
 * In the Assignment phase it offers only the contexts the final write would accept for the target of the lookup:
 * the common filters and the filters of the role binding run in SQL with the target user, the role, the proposed
 * values and the actor of the lookup, the access adapter of the type checks each candidate, and so does the scope
 * membership the panel requires. The checks that do not depend on a context (tenant, membership of the target in
 * it, scope mode, role) are made once by `PanelDirectories` before the search. Without a selected
 * target there is nothing to evaluate and the list is empty. The other phases skip eligibility, never the type,
 * the structure or the selected tenant.
 *
 * The owner of a candidate comes from `tenantOf()` of the definition, which may be any callback, so no SQL tenant
 * column is assumed: candidates are read in stable key order in chunks, a candidate of another tenant never uses up
 * the limit, and the scan goes on until the limit is filled or the candidates end. Reading `maxScan` candidates
 * without either outcome raises `DirectoryScanLimitException` instead of returning a partial list.
 *
 *
 * @phpstan-import-type Filter from \AzGuard\Scopes\AssignmentScopeSettings
 */
final readonly class QueryScopeDirectory implements AssignmentScopeDirectory
{
    public const int CHUNK = 200;

    public const int MAX_SCAN = 10000;

    public function __construct(
        private Container $container,
        private int $chunk = self::CHUNK,
        private int $maxScan = self::MAX_SCAN,
    ) {
        if ($chunk < 1 || $maxScan < $chunk) {
            throw new DefinitionException('A scope directory needs a positive chunk size and a scan budget of at least one chunk.');
        }
    }

    /**
     * @return list<AssignmentScopeOption>
     */
    public function search(string $type, string $term, LookupContext $lookup, int $limit): array
    {
        $definition = $lookup->panel->scopeDefinition($type);

        if ($limit < 1 || ! $definition instanceof QueryableAssignmentScopeDefinition) {
            return [];
        }
        $assignment = $lookup->phase === AssignmentScopePhase::Assignment;

        if ($assignment && $lookup->subject === null) {
            return [];
        }
        $query = EligibilityBuilder::structural($definition);
        self::match($query, $term);

        if ($assignment) {
            $stages = $this->stages($definition, $lookup);

            if ($stages === null) {
                return [];
            }

            foreach ($stages as [$filters, $runtime]) {
                if ($filters !== []) {
                    EligibilityBuilder::apply($definition, $query, $filters, $runtime, $this->container);
                }
            }
        }
        $adapter = $assignment ? $this->adapter($type, $lookup) : null;
        $key = $query->getModel()->getQualifiedKeyName();
        $query->reorder()->orderBy($key);
        $options = [];
        $scanned = 0;
        $after = null;

        while (true) {
            $page = clone $query;

            if ($after !== null) {
                $page->where($key, '>', $after);
            }
            $records = $page->limit($this->chunk)->get();
            $scanned += $records->count();
            $owned = [];

            foreach ($records as $record) {
                if ($definition->tenantOf($record)->equals($lookup->scope->tenant)) {
                    $owned[] = $record;
                }
            }

            foreach ($this->members($assignment, $type, $this->adapted($adapter, $type, $owned, $lookup), $lookup) as $record) {
                $options[] = new AssignmentScopeOption(AssignmentScopeRef::of($type, ModelKey::of($record)), self::label($record));

                if (count($options) >= $limit) {
                    return $options;
                }
            }

            if ($records->count() < $this->chunk) {
                return $options;
            }

            if ($scanned >= $this->maxScan) {
                throw new DirectoryScanLimitException('Directory of assignment scope type "'.$type.'" read '.$scanned.' candidates without filling the limit or reaching the end.');
            }
            $after = $records->last()?->getKey();
        }
    }

    /**
     * The scope of another tenant, a missing record or a foreign type is not described. Eligibility is not part of
     * a description: the write validates what the interface picked.
     */
    public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption
    {
        $definition = $context->isGlobal() ? null : $lookup->panel->scopeDefinition($context->type() ?? '');
        $resolved = $definition?->resolve($context);

        if ($resolved === null || ! $resolved->ref->equals($context) || ! $resolved->tenant->equals($lookup->scope->tenant)) {
            return null;
        }

        return new AssignmentScopeOption($context, $resolved->record === null ? (string) $context->id() : self::label($resolved->record));
    }

    /**
     * The filters of the common check with no role and of every binding of the role, each with its own runtime;
     * null when the role is not granted in this type.
     *
     * @return list<array{list<Filter>, AssignmentScopeRuntime}>|null
     */
    private function stages(QueryableAssignmentScopeDefinition $definition, LookupContext $lookup): ?array
    {
        $stages = [[$this->filters($definition), $this->runtime($lookup, withRole: false)]];

        if ($lookup->role === null) {
            return $stages;
        }
        $bindings = RoleBindings::of($lookup->role, $definition, $this->container);

        if ($bindings === []) {
            return null;
        }
        $runtime = $this->runtime($lookup, withRole: true);

        foreach ($bindings as $binding) {
            $stages[] = [$this->filters($binding), $runtime];
        }

        return $stages;
    }

    /** @return list<Filter> */
    private function filters(AssignmentScopeDefinition $definition): array
    {
        return $definition instanceof ConfigurableAssignmentScopeDefinition ? $definition->settings()->filters : [];
    }

    private function runtime(LookupContext $lookup, bool $withRole): AssignmentScopeRuntime
    {
        return new AssignmentScopeRuntime(
            panel: $lookup->panel, scope: $lookup->scope, subject: $lookup->subject ?? throw new DefinitionException('An assignment lookup needs a target subject.'),
            user: $lookup->user, role: $withRole ? $lookup->role : null, grant: null, actor: $lookup->actor, actorModel: $lookup->actorModel,
            now: $lookup->now, phase: $lookup->phase, proposed: $lookup->proposed,
        );
    }

    private function adapter(string $type, LookupContext $lookup): ?AssignmentScopeAccessAdapter
    {
        $declared = ScopeConfiguration::adapters($lookup->panel)[$type] ?? null;
        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if ($adapter !== null && ! $adapter instanceof AssignmentScopeAccessAdapter) {
            throw new DefinitionException('Scope adapter resolver did not return '.AssignmentScopeAccessAdapter::class.'.');
        }

        return $adapter;
    }

    /**
     * Candidates the target is a member of, when the panel requires scope membership: the write checks it too.
     *
     * @param  list<Model>  $records
     * @return list<Model>
     */
    private function members(bool $assignment, string $type, array $records, LookupContext $lookup): array
    {
        $declared = $assignment ? ScopeConfiguration::membership($lookup->panel) : null;

        if ($declared === null || $lookup->subject === null) {
            return $records;
        }
        $membership = is_string($declared) ? $this->container->make($declared) : $declared;

        if (! $membership instanceof AssignmentScopeMembership) {
            throw new DefinitionException('Scope membership resolver did not return '.AssignmentScopeMembership::class.'.');
        }
        $subject = $lookup->subject;

        return array_values(array_filter($records, static fn (Model $record): bool => $membership->isMember($subject, AssignmentScopeRef::of($type, ModelKey::of($record)))));
    }

    /**
     * Candidates the access adapter of the type allows for the target, for the common check and for the role.
     *
     * @param  list<Model>  $records
     * @return list<Model>
     */
    private function adapted(?AssignmentScopeAccessAdapter $adapter, string $type, array $records, LookupContext $lookup): array
    {
        if ($adapter === null) {
            return $records;
        }

        foreach ([false, ...($lookup->role === null ? [] : [true])] as $withRole) {
            if ($records === []) {
                break;
            }
            $refs = array_map(static fn (Model $record): AssignmentScopeRef => AssignmentScopeRef::of($type, ModelKey::of($record)), $records);
            $allowed = $adapter->allowsMany($refs, $this->runtime($lookup, $withRole));
            $records = array_values(array_filter($records, static fn (Model $record, int $i): bool => $allowed[$refs[$i]->key()] ?? false, ARRAY_FILTER_USE_BOTH));
        }

        return $records;
    }

    /** `azguardLabel()` of the record when it gives a non-empty string, else its key. */
    private static function label(Model $record): string
    {
        if (method_exists($record, 'azguardLabel')) {
            $label = $record->azguardLabel();

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return ModelKey::of($record);
    }

    /**
     * Restricts the query to rows whose key equals the term or whose `azguardSearchColumns()` contain it; an empty
     * term restricts nothing. The term is always a bound value.
     *
     * @param  Builder<Model>  $query
     */
    private static function match(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $model = $query->getModel();
        $driver = $model->getConnection()->getDriverName();
        // The explicitly declared LIKE escape character and wildcards of the bound term all match themselves.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';
        $columns = self::searchColumns($model);
        // An integer key never equals free text; comparing them would fail on a strict engine.
        $keyCanEqual = ! ModelKey::isInteger($model) || (ctype_digit($term) && strlen($term) <= 18);

        $query->where(function (Builder $group) use ($model, $term, $pattern, $operator, $columns, $keyCanEqual): void {
            $matchers = 0;

            if ($keyCanEqual) {
                $group->orWhere($model->getQualifiedKeyName(), $term);
                $matchers++;
            }

            foreach ($columns as $column) {
                $group->orWhere($model->qualifyColumn($column), $operator, new Expression("? escape '!'"));
                $group->getQuery()->addBinding($pattern, 'where');
                $matchers++;
            }

            if ($matchers === 0) {
                $group->whereRaw('0 = 1');
            }
        });
    }

    /** @return list<string> */
    private static function searchColumns(Model $model): array
    {
        if (! method_exists($model, 'azguardSearchColumns') || ! (new ReflectionMethod($model, 'azguardSearchColumns'))->isStatic()) {
            return [];
        }
        $columns = (new ReflectionMethod($model, 'azguardSearchColumns'))->invoke(null);

        if (! is_array($columns)) {
            throw new DefinitionException($model::class.'::azguardSearchColumns() must return a list of column names.');
        }

        foreach ($columns as $column) {
            if (! is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new DefinitionException($model::class.'::azguardSearchColumns() must list plain column names, got '.json_encode($column).'.');
            }
        }

        return array_values($columns);
    }
}
