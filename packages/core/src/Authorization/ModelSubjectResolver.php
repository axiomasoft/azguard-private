<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use Illuminate\Database\Eloquent\Model;

final class ModelSubjectResolver
{
    /** The model the caller passed with the request, or a fresh read for a bare reference. */
    public function forRequest(Panel $panel, AccessRequest $request): ?Model
    {
        return self::given($panel, $request->subjectModel(), $request->subject()) ?? $this->resolve($panel, $request->subject());
    }

    /**
     * The stored model whose key is exactly the id of the reference. An id that an integer key cannot hold (`a/b`, `01`)
     * names no model and is not sent to the database: PostgreSQL rejects it with an error (and aborts the surrounding
     * transaction) where SQLite and MySQL coerce it to another row. A row the database matched by coercion or by a
     * case-insensitive collation is not the subject either.
     */
    public function resolve(Panel $panel, SubjectRef $subject): ?Model
    {
        foreach ($panel->subjectModels() as $class) {
            $model = new $class;

            if ($model->getMorphClass() === $subject->type()) {
                if (! self::keyCanHold($model, $subject->id())) {
                    return null;
                }
                $found = $model->newQuery()->whereKey($subject->id())->first();

                return $found !== null && self::keyOf($found) === $subject->id() ? $found : null;
            }
        }

        return null;
    }

    /** Whether the key column of the model can hold the id as written: any id for a string key, a canonical integer otherwise. */
    public static function keyCanHold(Model $model, string $id): bool
    {
        if (! in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return true;
        }

        return preg_match('/^-?(0|[1-9][0-9]*)$/', $id) === 1 && (string) (int) $id === $id;
    }

    private static function keyOf(Model $model): ?string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? (string) $key : null;
    }

    /**
     * The given model when it is a stored instance of the very class a read would return for the reference (a subclass
     * or another model with the same morph alias is read again), otherwise null.
     */
    public static function given(Panel $panel, mixed $model, SubjectRef $subject): ?Model
    {
        if (! $model instanceof Model || ! $model->exists || $model->getMorphClass() !== $subject->type()) {
            return null;
        }
        $key = $model->getKey();

        if ((! is_int($key) && ! is_string($key)) || (string) $key !== $subject->id()) {
            return null;
        }
        foreach ($panel->subjectModels() as $class) {
            if ((new $class)->getMorphClass() === $subject->type()) {
                return $model::class === $class ? $model : null;
            }
        }

        return null;
    }
}
