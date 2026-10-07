<?php

declare(strict_types=1);

namespace AzGuard\Panels;

/**
 * How a panel answers Laravel Gate for the abilities it owns.
 *
 * @api
 */
enum GateMode: string
{
    /** The panel's answer for an ability it owns is final; abilities it does not own are left to Laravel. */
    case Authoritative = 'authoritative';
}
