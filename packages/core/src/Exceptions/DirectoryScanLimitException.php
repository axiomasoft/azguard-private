<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

/**
 * A directory read its scan budget without filling the limit or proving the end of the candidates; a partial
 * list is never returned as a complete one.
 */
final class DirectoryScanLimitException extends AzGuardException
{
    public function code(): string
    {
        return 'directory_scan_limit';
    }
}
