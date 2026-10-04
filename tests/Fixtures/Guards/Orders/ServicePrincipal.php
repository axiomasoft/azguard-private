<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Orders;

use Illuminate\Database\Eloquent\Model;

final class ServicePrincipal extends Model
{
    protected $table = 'service_principals';

    public $timestamps = false;
}
