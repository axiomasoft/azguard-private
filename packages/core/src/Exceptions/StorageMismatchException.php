<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

#[Api(since: '0.7')]
final class StorageMismatchException extends StorageException
{
    public function code(): string
    {
        return 'storage_mismatch';
    }
}
