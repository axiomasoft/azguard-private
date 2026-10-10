<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * The model is not a subject of the panel.
 */
final class SubjectNotAcceptedException extends DefinitionException
{
    public function code(): string
    {
        return 'subject_not_accepted';
    }
}
