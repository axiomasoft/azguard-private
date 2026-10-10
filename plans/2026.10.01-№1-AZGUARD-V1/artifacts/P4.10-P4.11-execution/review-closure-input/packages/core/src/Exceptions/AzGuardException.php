<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use RuntimeException;

/**
 * Base of every AzGuard error; `code()` is the stable snake_case machine code.
 */
abstract class AzGuardException extends RuntimeException
{
    abstract public function code(): string;
}
