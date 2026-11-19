<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Errors\AzGuardErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Exceptions\CodedException;

/**
 * Base of every AzGuard error; `code()` is the stable snake_case machine code.
 */
#[Api(since: '1.0')]
abstract class AzGuardException extends CodedException
{
    abstract public function code(): string;

    public function errorDescriptor(): ErrorDescriptor
    {
        return AzGuardErrorCode::descriptorFor($this->code());
    }
}
