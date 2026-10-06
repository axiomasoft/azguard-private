<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Models;

use Illuminate\Database\Eloquent\Model;

final class Client extends Model
{
    protected $table = 'clients';

    protected $guarded = [];

    public $timestamps = false;
}
