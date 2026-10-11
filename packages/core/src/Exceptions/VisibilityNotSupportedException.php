<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;
use RuntimeException;

/**
 * Safe diagnostics: component and category, never a source payload or exception message.
 */
#[Api(since: '0.7')]
final class VisibilityNotSupportedException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unsupported', public readonly ?string $component = null)
    {
        parent::__construct('Exact visibility is unavailable: '.$reason.($component === null ? '' : ' ('.$component.')').'.');
    }
}
