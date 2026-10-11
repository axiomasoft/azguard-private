<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

#[Api(since: '0.7')]
abstract class StorageException extends AzGuardException {}
