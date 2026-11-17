<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Authorization;

use AzGuard\Errors\AzGuardErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

#[Api(since: '1.0')]
final class RecursionDetectedException extends CodedException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: AzGuardErrorCode::RecursionDetected,
            category: ErrorCategory::Unavailable,
            retryable: true,
        );
    }
}
