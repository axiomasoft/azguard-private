<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

/**
 * The model is not a subject of the panel.
 */
#[Api(since: '1.0')]
final class SubjectNotAcceptedException extends DefinitionException
{
    public function code(): string
    {
        return 'subject_not_accepted';
    }
}
