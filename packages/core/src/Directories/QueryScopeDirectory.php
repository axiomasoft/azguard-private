<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Contracts\Scopes\AssignmentScopeAccessAdapter;
use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\DirectoryScanLimitException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Scopes\Query\EligibilityBuilder;
use AzGuard\Scopes\RoleBindings;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Default directory of an assignment scope backed by an Eloquent query.
 *
 * In the Assignment phase it offers only the contexts the final write would accept for the target of the lookup:
 * the common filters and the filters of the role binding run in SQL with the target user, the role, the proposed
 * values and the actor of the lookup, and the access adapter of the type checks each candidate. Without a selected
 * target there is nothing to evaluate and the list is empty. The other phases skip eligibility, never the type,
 * the structure or the selected tenant.
 *
 * The owner of a candidate comes from `tenantOf()` of the definition, which may be any callback, so no SQL tenant
 * column is assumed: candidates are read in stable key order in chunks, a candidate of another tenant never uses up
 * the limit, and the scan goes on until the limit is filled or the candidates end. Reading `maxScan` candidates
 * without either outcome raises `DirectoryScanLimitException` instead of returning a partial list.
 *
 * @api
 */
final readonly class QueryScopeDirectory implements AssignmentScopeDirectory
{
    public const CHUNK = 200;

    public const MAX_SCAN = 10000;

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
        ModelLookup::match($query, $term);

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

            foreach ($this->adapted($adapter, $type, $owned, $lookup) as $record) {
                $options[] = new AssignmentScopeOption(AssignmentScopeRef::of($type, (string) $record->getKey()), ModelLookup::label($record));

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

        return new AssignmentScopeOption($context, $resolved->record === null ? (string) $context->id() : ModelLookup::label($resolved->record));
    }

    /**
     * The filters of the common check with no role and of every binding of the role, each with its own runtime;
     * null when the role is not granted in this type.
     *
     * @return list<array{list<mixed>, AssignmentScopeRuntime}>|null
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

    /** @return list<mixed> */
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
        $declared = $lookup->panel->scopes()->adapters()[$type] ?? null;
        $adapter = is_string($declared) ? $this->container->make($declared) : $declared;

        if ($adapter !== null && ! $adapter instanceof AssignmentScopeAccessAdapter) {
            throw new DefinitionException('Scope adapter resolver did not return '.AssignmentScopeAccessAdapter::class.'.');
        }

        return $adapter;
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
            $refs = array_map(static fn (Model $record): AssignmentScopeRef => AssignmentScopeRef::of($type, (string) $record->getKey()), $records);
            $allowed = $adapter->allowsMany($refs, $this->runtime($lookup, $withRole));
            $records = array_values(array_filter($records, static fn (Model $record, int $i): bool => $allowed[$refs[$i]->key()] ?? false, ARRAY_FILTER_USE_BOTH));
        }

        return $records;
    }
}
