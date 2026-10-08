<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Testing\FakeSubject;
use AzGuard\Tests\Fixtures\Contracts\GoodSubjectResolver;
use Illuminate\Database\Eloquent\Model;

/** A resolver whose model belongs to another subject than the reference. */
final class CrossedSubjectResolver extends GoodSubjectResolver
{
    public function model(SubjectRef $ref): ?Model
    {
        $model = parent::model($ref);

        return $model === null ? null : FakeSubject::of(((int) $ref->id() % 3) + 1);
    }
}
