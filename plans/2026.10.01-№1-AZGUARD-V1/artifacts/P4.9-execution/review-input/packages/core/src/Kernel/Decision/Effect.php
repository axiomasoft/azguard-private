<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

enum Effect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case NotApplicable = 'not_applicable';
}
