<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Models\DirectGrant;

class OtherConnectionDirectGrant extends DirectGrant
{
    protected $connection = 'other';
}
