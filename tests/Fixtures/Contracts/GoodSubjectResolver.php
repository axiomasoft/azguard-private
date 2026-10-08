<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts;

use AzGuard\Contracts\Subjects\SubjectResolver;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Testing\FakeSubject;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Resolves fake subjects 1 to 3 and references to them; knows no other subject. */
class GoodSubjectResolver implements SubjectResolver
{
    public function resolve(mixed $subject): SubjectRef
    {
        return match (true) {
            $subject instanceof SubjectRef => $subject,
            $subject instanceof FakeSubject => SubjectRef::of(FakeSubject::TYPE, $subject->getKey()),
            default => throw new InvalidArgumentException(get_debug_type($subject).' is not a subject.'),
        };
    }

    public function model(SubjectRef $ref): ?Model
    {
        return $ref->type() === FakeSubject::TYPE && in_array($ref->id(), ['1', '2', '3'], true) ? FakeSubject::of((int) $ref->id()) : null;
    }
}
