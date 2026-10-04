<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop;

use Illuminate\Database\Eloquent\Model;

final class Store extends Model
{
    protected $table = 'stores';

    public $timestamps = false;
}
