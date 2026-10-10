<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\PoliciesGate;

enum GatePermission: string
{
    case Access = 'beta.access';
    case Veto = 'beta.veto';
    case Php = 'beta.php';
}
