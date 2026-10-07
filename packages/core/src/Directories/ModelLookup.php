<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Exceptions\DefinitionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use ReflectionMethod;

/**
 * What the default directories read from a model: its label and the columns a term is matched against.
 *
 * A model offers `azguardLabel(): string` and a static `azguardSearchColumns(): list<string>`; without them the key
 * is the label and the only match. The term is always a bound value.
 *
 * @internal
 */
final class ModelLookup
{
    public static function label(Model $record): string
    {
        if (method_exists($record, 'azguardLabel')) {
            $label = $record->azguardLabel();

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return (string) $record->getKey();
    }

    /**
     * Restricts the query to rows whose key equals the term or whose declared columns contain it; an empty term
     * restricts nothing.
     *
     * @param  Builder<Model>  $query
     */
    public static function match(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $model = $query->getModel();
        $driver = $model->getConnection()->getDriverName();
        $pattern = '%'.self::escaped($term).'%';
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';
        $columns = self::searchColumns($model);

        $query->where(function (Builder $group) use ($model, $term, $pattern, $operator, $columns): void {
            $matchers = 0;

            if (self::keyCanEqual($model, $term)) {
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

    /**
     * @return list<string>
     */
    public static function searchColumns(Model $model): array
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

    /** The explicitly declared LIKE escape character and wildcards of the bound term all match themselves. */
    private static function escaped(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    /** An integer key never equals free text; comparing them would fail on a strict engine. */
    private static function keyCanEqual(Model $model, string $term): bool
    {
        return $model->getKeyType() !== 'int' || (ctype_digit($term) && strlen($term) <= 18);
    }
}
