<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics;

/**
 * How serious a doctor finding is: an error fails `azguard:doctor`, a warning is reported only.
 *
 * @api
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
