<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use RuntimeException;

/**
 * An argument or option of an AzGuard command is malformed or missing: `CommandSupport::attempt()` prints the message
 * and the command exits with code 2.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class InvalidCommandInput extends RuntimeException {}
