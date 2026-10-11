<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Subjects;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\SubjectRef;
use Illuminate\Database\Eloquent\Model;

/**
 * Converts a subject value to its reference and loads the model behind a reference.
 */
#[Spi(kind: SpiKind::Implement, since: '0.7')]
interface SubjectResolver
{
    public function resolve(mixed $subject): SubjectRef;

    public function model(SubjectRef $ref): ?Model;
}
