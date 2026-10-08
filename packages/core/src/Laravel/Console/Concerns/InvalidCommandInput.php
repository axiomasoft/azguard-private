<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Concerns;

use InvalidArgumentException;

/**
 * @internal An argument or option of an AzGuard command is malformed or missing; the command exits with code 2.
 */
final class InvalidCommandInput extends InvalidArgumentException {}
