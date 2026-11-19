<?php

declare(strict_types=1);

namespace AzGuard\Exceptions\Authorization;

use AzGuard\Exceptions\AzGuardException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
abstract class AuthorizationEngineException extends AzGuardException {}
