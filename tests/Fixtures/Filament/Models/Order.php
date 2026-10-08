<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Models;

use Illuminate\Database\Eloquent\Model;

final class Order extends Model
{
    protected $table = 'orders';

    public $timestamps = false;

    protected $guarded = [];
}
