<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

enum OrderPermission: string
{
    case View = 'orders.view';
    case Update = 'orders.update';
}
