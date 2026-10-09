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

    public function resolve(Panel $panel, SubjectRef $subject): ?Model
    {
        foreach ($panel->subjectModels() as $class) {
            $model = new $class;

            if ($model->getMorphClass() === $subject->type()) {
                return $model->newQuery()->whereKey($subject->id())->first();
            }
        }

        return null;
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
