<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Models;

use Illuminate\Database\Eloquent\Model;

final class City extends Model
{
    protected $table = 'cities';

    protected $guarded = [];

    public $timestamps = false;
}
