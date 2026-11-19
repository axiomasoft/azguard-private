<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Authorization;

use AzGuard\Errors\AzGuardErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

/**
 * Safe diagnostics: component and category, never a source payload or exception message.
 */
#[Api(since: '1.0')]
final class VisibilityNotSupportedException extends CodedException
{
    public function __construct(public readonly string $reason = 'unsupported', public readonly ?string $component = null)
    {
        parent::__construct('Exact visibility is unavailable: '.$reason.($component === null ? '' : ' ('.$component.')').'.');
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: AzGuardErrorCode::VisibilityNotSupported,
            category: ErrorCategory::Unsupported,
            retryable: false,
            details: array_filter([
                'reason' => $this->reason,
                'component' => $this->component,
            ], static fn (?string $value): bool => $value !== null),
        );
    }
}
