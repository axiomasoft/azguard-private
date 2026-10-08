<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Testing\FakeSubject;
use AzGuard\Tests\Fixtures\Contracts\GoodSubjectResolver;
use Illuminate\Database\Eloquent\Model;

/** A resolver that finds a model for every reference, also for a subject that does not exist. */
final class GhostSubjectResolver extends GoodSubjectResolver
{
    public function model(SubjectRef $ref): ?Model
    {
        return FakeSubject::of($ref->id());
    }
}
