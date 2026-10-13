<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;
use RuntimeException;

/**
 * Base of every AzGuard error; `code()` is the stable snake_case machine code.
 */
#[Api(since: '1.0')]
abstract class AzGuardException extends RuntimeException
{
    abstract public function code(): string;
}
