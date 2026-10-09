<?php

declare(strict_types=1);

namespace AzGuard\Support;

use AzGuard\Exceptions\InvalidIdentityException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Looks a model up by the id of an identity reference (`SubjectRef`, `TenantRef`, `AssignmentScopeRef`), matching
 * only the row whose key is that id as written.
 *
 * A reference id is text; a key column is often an integer. Compared directly, SQLite and MySQL coerce `05` or `5abc`
 * to row 5, a case-insensitive collation matches `ABC` to `abc`, and PostgreSQL rejects `a/b` with an error that also
 * aborts the surrounding transaction. AzGuard resolves every subject, tenant and context through this class, so use it
 * for ids that come from a request, a form or a queue payload when the answer must agree with AzGuard's.
 *
 * @api
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

    /**
     * The key of a stored model as the id of a reference.
     *
     * @throws InvalidIdentityException when the model has no int or string key (it is not saved yet)
     */
    public static function of(Model $model): string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidIdentityException($model::class.' has no key: save the model first.');
        }

        return (string) $key;
    }

    /** Whether the key of the model is exactly the id; a model without a key matches no id. */
    public static function matches(Model $model, string $id): bool
    {
        $key = $model->getKey();

        return (is_int($key) || is_string($key)) && (string) $key === $id;
    }

    /**
     * Constrains the query to the rows whose key is one of the ids. An id the key cannot hold is never sent to the
     * database; a query left with no id (a global reference has none) matches nothing.
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

        return $found !== null && self::matches($found, $id) ? $found : null;
    }
}
