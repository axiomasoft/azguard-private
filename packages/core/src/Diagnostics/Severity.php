<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics;

use AzGuard\Attributes\Api;

/**
 * How serious a doctor finding is: an error fails `azguard:doctor`, a warning is reported only.
 *
 * @closed
 */
#[Api(since: '0.7')]
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
