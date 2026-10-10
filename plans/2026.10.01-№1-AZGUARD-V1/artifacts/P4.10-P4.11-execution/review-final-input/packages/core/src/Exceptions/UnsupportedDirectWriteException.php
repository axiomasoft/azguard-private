<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

final class UnsupportedDirectWriteException extends StorageException
{
    public function code(): string
    {
        return 'unsupported_direct_write';
    }
}
