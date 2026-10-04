<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use Illuminate\Database\Eloquent\Model;

final class ModelSubjectResolver
{
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
}
