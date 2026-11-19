<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Subjects;

use AzGuard\Kernel\Identity\SubjectRef;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Converts a subject value to its reference and loads the model behind a reference.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SubjectResolver
{
    public function resolve(mixed $subject): SubjectRef;

    public function model(SubjectRef $ref): ?Model;
}
