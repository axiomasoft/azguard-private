<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal The one rule for looking a model up by the id of a reference. An id is text (`IdentityCodec`); a key column
 * may be an integer. SQLite and MySQL coerce `5abc` or `05` to row 5, PostgreSQL rejects the comparison with an error
 * (and aborts the surrounding transaction), and a case-insensitive collation matches `ABC` to `abc`. Every lookup goes
 * through here so a single check, a batch and a list all agree that only the row whose key is the id as written matches.
 */
final class ModelKey
{
    private function __construct() {}

    /** Whether the key of the model is an integer column (`int` and `integer` are both valid Eloquent key types). */
    public static function isInteger(Model $model): bool
    {
        return in_array($model->getKeyType(), ['int', 'integer'], true);
    }

    /** Whether the key column of the model can hold the id as written: any id for a string key, a canonical integer otherwise. */
    public static function canHold(Model $model, string $id): bool
    {
        if (! self::isInteger($model)) {
            return true;
        }

        return preg_match('/^-?(0|[1-9][0-9]*)$/', $id) === 1 && (string) (int) $id === $id;
    }

    /** The key of a stored model as the text of a reference, or null when it has none. */
    public static function of(Model $model): ?string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? (string) $key : null;
    }

    /**
     * Constrains the query to the rows whose key is one of the ids; an id the key cannot hold is never sent to the
     * database, and a query left with no id (a global reference has none) matches nothing.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  string|list<string|null>|null  $ids
     * @return Builder<TModel>
     */
    public static function where(Builder $query, string|array|null $ids): Builder
    {
        $model = $query->getModel();
        $held = array_values(array_filter(is_array($ids) ? $ids : [$ids],
            static fn (?string $id): bool => $id !== null && self::canHold($model, $id)));

        return $held === [] ? $query->whereRaw('0 = 1') : $query->whereKey(is_array($ids) ? $held : $held[0]);
    }

    /**
     * The row whose key is exactly the id, never one the database matched by coercion or collation. An id the key
     * cannot hold, or no id at all (a global reference), is answered without a query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    public static function find(Builder $query, ?string $id): ?Model
    {
        if ($id === null || ! self::canHold($query->getModel(), $id)) {
            return null;
        }
        $found = $query->whereKey($id)->first();

        return $found !== null && self::of($found) === $id ? $found : null;
    }
}
