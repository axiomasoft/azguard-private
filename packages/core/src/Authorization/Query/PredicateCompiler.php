<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Query;

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\AccessPredicate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionNamedType;

/** Compiles exact descriptors as one AND group; never executes a resource query. */
final class PredicateCompiler
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, AccessPredicate $predicate): Builder
    {
        $predicate->assertBoolean();
        // Freeze native scopes on a clone before inspecting the effective SQL.
        // Deferred scopes must not add an unfiltered UNION after this check.
        $effective = (clone $query)->applyScopes()->withoutGlobalScopes();

        if ($effective->getQuery()->from !== $effective->getModel()->getTable()) {
            throw new DefinitionException('Exact predicates require the model table as the query root.');
        }

        if ($effective->getQuery()->unions) {
            throw new DefinitionException('Exact predicates cannot constrain every arm of a UNION query.');
        }
        foreach ($effective->getQuery()->wheres as $where) {
            if (str_starts_with($where['boolean'], 'or')) {
                throw new DefinitionException('Group host OR conditions before applying exact predicates.');
            }
        }
        $columns = [];
        // Build on a detached group: unsupported siblings and invalid identifiers
        // leave the host query untouched, including its bindings and structure.
        $group = $effective->getModel()->newModelQuery();
        $group->setQuery($effective->getQuery()->forNestedWhere());
        $this->compile($group, $predicate, $effective->getModel(), $columns);
        $effective->getQuery()->addNestedWhereQuery($group->getQuery());
        $query->setQuery($effective->getQuery());
        $query->setEagerLoads($effective->getEagerLoads());
        $query->withoutGlobalScopes();

        return $query;
    }

    /**
     * @param Builder<*> $query
     * @param  array<string, list<string>>  $columns
     */
    private function compile(Builder $query, AccessPredicate $predicate, Model $schemaModel, array &$columns): void
    {
        switch ($predicate->operation) {
            case 'pass':
                $query->whereRaw('1 = 1');

                break;
            case 'deny':
                $query->whereRaw('1 = 0');

                break;
            case 'all':
            case 'any':
                if ($predicate->operands === []) {
                    $query->whereRaw($predicate->operation === 'all' ? '1 = 1' : '1 = 0');

                    break;
                }
                // Always join the OR expression with AND to prior relation keys.
                $query->where(function (Builder $logical) use ($predicate, $schemaModel, &$columns): void {
                    foreach ($predicate->operands as $operand) {
                        $logical->where(function (Builder $nested) use ($operand, $schemaModel, &$columns): void {
                            $this->compile($nested, $operand, $schemaModel, $columns);
                        }, boolean: $predicate->operation === 'any' ? 'or' : 'and');
                    }
                });

                break;
            case 'not':
                $query->whereNot(function (Builder $nested) use ($predicate, $schemaModel, &$columns): void {
                    $this->compile($nested, $predicate->operands[0], $schemaModel, $columns);
                });

                break;
            case 'branch':
                $query->where(function (Builder $nested) use ($predicate, $schemaModel, &$columns): void {
                    $this->compile($nested, $predicate->operands[0], $schemaModel, $columns);
                });

                break;
            case 'exists':
                $path = (string) $predicate->identifier;
                $relatedModel = $this->relation($schemaModel, $path);
                $query->whereHas($path, function (Builder $related) use ($predicate, $relatedModel, &$columns): void {
                    $this->compile($related, $predicate->operands[0], $relatedModel, $columns);
                });

                break;
            case 'eq':
            case 'gte':
            case 'lt':
            case 'in':
            case 'is_null':
            case 'not_null':
                $this->comparison($query, $predicate, $schemaModel, $columns);

                break;
            default:
                throw new DefinitionException('An unsupported access predicate cannot be compiled exactly.');
        }
    }

    /**
     * @param Builder<*> $query
     * @param  array<string, list<string>>  $columns
     */
    private function comparison(Builder $query, AccessPredicate $predicate, Model $schemaModel, array &$columns): void
    {
        $model = $query->getModel();
        $column = (string) $predicate->identifier;
        $table = $model->getTable();

        $selfAlias = preg_match('/\Alaravel_reserved_[0-9]+\z/', $table) === 1
            && $query->getQuery()->from === $schemaModel->getTable().' as '.$table;

        if ($query->getQuery()->from !== $table && ! $selfAlias) {
            throw new DefinitionException('Exact predicates require the model table as the query root.');
        }
        $schemaTable = $schemaModel->getTable();
        $key = $schemaModel->getConnection()->getName().'|'.$schemaTable;
        $columns[$key] ??= $schemaModel->getConnection()->getSchemaBuilder()->getColumnListing($schemaTable);

        if (! in_array($column, $columns[$key], true)) {
            throw new DefinitionException('Unknown exact predicate column on the resource model.');
        }
        $column = $model->qualifyColumn($column);
        $value = $predicate->values[0] ?? null;

        if ($predicate->operation === 'is_null' || ($predicate->operation === 'eq' && $value === null)) {
            $query->whereNull($column);

            return;
        }

        if ($predicate->operation === 'not_null') {
            $query->whereNotNull($column);

            return;
        }

        if ($predicate->operation === 'in') {
            $values = array_values(array_filter($predicate->values, static fn (bool|int|float|string|null $value): bool => $value !== null));
            $query->where(function (Builder $nested) use ($column, $values, $predicate): void {
                $nested->whereNotNull($column)->whereIn($column, $values);

                if (in_array(null, $predicate->values, true)) {
                    $nested->orWhereNull($column);
                }
            });

            return;
        }

        // Without the explicit NULL guard, NOT(column = value) would lose NULL rows.
        $query->whereNotNull($column)->where($column, match ($predicate->operation) {
            'eq' => '=', 'gte' => '>=', 'lt' => '<',
            default => throw new DefinitionException('Invalid exact comparison operator.'),
        }, $value);
    }

    private function relation(Model $model, string $path): Model
    {
        foreach (explode('.', $path) as $name) {
            if (! method_exists($model, $name)) {
                throw new DefinitionException('Unknown exact predicate relation on the resource model.');
            }
            $method = new ReflectionMethod($model, $name);

            if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() !== 0) {
                throw new DefinitionException('Exact predicates require a public relation method without required arguments.');
            }
            $return = $method->getReturnType();

            if (! $return instanceof ReflectionNamedType || ! is_a($return->getName(), Relation::class, true)) {
                throw new DefinitionException('Exact relation methods must declare an Eloquent Relation return type.');
            }
            $relation = Relation::noConstraints(fn () => $model->{$name}());

            if (! $relation instanceof Relation || $relation instanceof MorphTo) {
                throw new DefinitionException('Exact predicates require a concrete Eloquent relation.');
            }

            if ($relation->getRelated()->getConnection() !== $model->getConnection()) {
                throw new DefinitionException('Cross-connection exact relation predicates are unsupported.');
            }
            $model = $relation->getRelated();
        }

        return $model;
    }
}
