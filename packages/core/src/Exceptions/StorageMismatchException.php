<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

final class StorageMismatchException extends StorageException
{
    public function code(): string
    {
        return 'storage_mismatch';
    }
}
