<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

use AzGuard\Attributes\Api;

#[Api(since: '1.0')]
abstract class PluginException extends AzGuardException {}
